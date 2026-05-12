<?php
// admin/change_password.php
require_once '../includes/config.php';
require_once '../includes/db_connection.php';
require_once '../includes/security.php';
require_once '../includes/auth.php';

$auth->requireLogin();
$current_user = $auth->getCurrentUser();

// If current user is not found in database, log out
if (!$current_user) {
    error_log("change_password.php: Current user not found, logging out");
    $auth->logout();
    exit();
}

$db = Database::getInstance();

// Get pending users count (for sidebar badge)
$pending_count = 0;
if ($current_user['role'] === 'admin') {
    $pending_count = $db->getSingle("SELECT COUNT(*) as cnt FROM pending_users WHERE status = 'pending'")['cnt'] ?? 0;
}

$message = '';
$error = '';

// Handle password change
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please refresh the page.';
    } else {
        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        // Validate inputs
        if (empty($current_password)) {
            $error = 'Current password is required.';
        } elseif (empty($new_password)) {
            $error = 'New password is required.';
        } elseif (strlen($new_password) < 8) {
            $error = 'New password must be at least 8 characters long.';
        } elseif (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]{8,}$/', $new_password)) {
            $error = 'Password must contain at least one uppercase letter, one lowercase letter, one number, and one special character.';
        } elseif ($new_password !== $confirm_password) {
            $error = 'New passwords do not match.';
        } else {
            // Verify current password
            $user = $db->getSingle(
                "SELECT user_id, password FROM users WHERE user_id = ?",
                [$current_user['user_id']],
                'i'
            );

            if (!$user) {
                $error = 'User not found.';
            } elseif (!password_verify($current_password, $user['password'])) {
                $error = 'Current password is incorrect.';
                
                // Log failed password change attempt
                Security::logEvent('PASSWORD_CHANGE_FAILED', 
                    "Failed password change attempt for user: {$current_user['username']}");
            } else {
                // Hash new password
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                
                // Update password in database
                $result = $db->execute(
                    "UPDATE users SET password = ?, updated_at = NOW() WHERE user_id = ?",
                    [$hashed_password, $current_user['user_id']],
                    'si'
                );

                if ($result) {
                    $message = 'Password changed successfully!';
                    
                    // Log successful password change
                    Security::logEvent('PASSWORD_CHANGE_SUCCESS', 
                        "Password changed for user: {$current_user['username']}");
                    
                    // Optional: Invalidate all other sessions except current
                    $db->execute(
                        "DELETE FROM user_sessions WHERE user_id = ? AND session_token != ?",
                        [$current_user['user_id'], $_SESSION['session_token'] ?? ''],
                        'is'
                    );
                    
                    // Clear form data
                    $_POST = [];
                } else {
                    $error = 'Failed to update password. Please try again.';
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
    <title>Change Password - <?php echo APP_NAME; ?></title>
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
        
        /* Sidebar - Full width */
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
        
        /* Sidebar - Collapsed state (shows only icons) */
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
        
        /* Hide sidebar logo when collapsed */
        #sidebar-wrapper.collapsed .sidebar-heading img {
            display: none;
        }
        
        #sidebar-wrapper .sidebar-heading {
            padding: 1.2rem 1rem;
            font-size: 1.4rem;
            font-weight: 600;
            letter-spacing: 0.5px;
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
            transition: all 0.3s ease;
        }
        
        /* Hamburger Menu Button - positioned in sidebar heading */
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
        
        /* Header logo - hidden by default, visible only when sidebar is collapsed */
        .header-logo {
            height: 30px;
            width: auto;
            margin-right: 10px;
            vertical-align: middle;
            transition: all 0.3s ease;
            display: none;
        }
        
        /* Show header logo when sidebar is collapsed */
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
            font-size: 1.2rem;
        }
        
        .navbar-right {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        
        .badge-count {
            font-size: 0.6rem;
            padding: 2px 5px;
        }
        
        .card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            background: white;
            margin-bottom: 20px;
            overflow: hidden;
        }
        
        .card-header {
            background: linear-gradient(135deg, #375a7f, #2c4a6b);
            color: white;
            padding: 20px 25px;
            border: none;
        }
        
        .card-header h5 {
            margin: 0;
            font-weight: 600;
        }
        
        .card-header i {
            margin-right: 10px;
        }
        
        .card-body {
            padding: 30px;
        }
        
        .form-group {
            margin-bottom: 25px;
        }
        
        .form-label {
            font-weight: 600;
            color: #495057;
            margin-bottom: 8px;
        }
        
        .form-control {
            padding: 12px 15px;
            border: 2px solid #e9ecef;
            border-radius: 10px;
            transition: all 0.3s;
        }
        
        .form-control:focus {
            border-color: #375a7f;
            box-shadow: 0 0 0 0.2rem rgba(55, 90, 127, 0.25);
        }
        
        /* Password strength meter */
        .password-strength {
            margin-top: 10px;
        }
        
        .strength-bar {
            height: 6px;
            border-radius: 3px;
            background: #e9ecef;
            overflow: hidden;
        }
        
        .strength-fill {
            height: 100%;
            width: 0%;
            transition: all 0.3s;
        }
        
        .strength-fill.weak { background: #dc3545; width: 25%; }
        .strength-fill.fair { background: #ffc107; width: 50%; }
        .strength-fill.good { background: #17a2b8; width: 75%; }
        .strength-fill.strong { background: #28a745; width: 100%; }
        
        .strength-text {
            font-size: 0.9rem;
            margin-top: 5px;
        }
        
        /* Password requirements */
        .password-requirements {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
            margin-top: 10px;
        }
        
        .requirement-item {
            display: flex;
            align-items: center;
            margin-bottom: 8px;
            font-size: 0.9rem;
            color: #6c757d;
            transition: all 0.3s;
        }
        
        .requirement-item.met {
            color: #28a745;
        }
        
        .requirement-item i {
            width: 20px;
            margin-right: 10px;
        }
        
        .requirement-item .fa-circle-check {
            color: #28a745;
        }
        
        .requirement-item .fa-circle {
            color: #dee2e6;
        }
        
        /* Password toggle */
        .password-field {
            position: relative;
        }
        
        .password-toggle {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #6c757d;
            z-index: 10;
        }
        
        .password-toggle:hover {
            color: #375a7f;
        }
        
        /* Buttons */
        .btn-primary {
            background: linear-gradient(135deg, #375a7f, #2c4a6b);
            border: none;
            padding: 12px 30px;
            font-weight: 600;
            border-radius: 10px;
            transition: all 0.3s;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(55, 90, 127, 0.3);
        }
        
        .btn-secondary {
            background: #6c757d;
            border: none;
            padding: 12px 30px;
            font-weight: 600;
            border-radius: 10px;
            transition: all 0.3s;
        }
        
        .btn-secondary:hover {
            background: #5a6268;
            transform: translateY(-2px);
        }
        
        .alert {
            border-radius: 10px;
            padding: 15px 20px;
            margin-bottom: 20px;
        }
        
        /* Toast notification */
        .toast-container {
            z-index: 1100;
        }
        
        .toast {
            border-radius: 10px;
        }
        
        /* Info box */
        .info-box {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
        }
        
        .info-box i {
            font-size: 2rem;
            margin-bottom: 10px;
        }
        
        .info-box h6 {
            font-size: 1.1rem;
            margin-bottom: 10px;
        }
        
        .info-box ul {
            margin-bottom: 0;
            padding-left: 20px;
        }
        
        .info-box li {
            margin-bottom: 5px;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .card-body {
                padding: 20px;
            }
            
            .btn-primary, .btn-secondary {
                width: 100%;
                margin-bottom: 10px;
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
                <a href="members.php" class="list-group-item"><i class="fas fa-users"></i><span>Members</span></a>
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
               
                <a href="../logout.php" class="list-group-item" onclick="return confirm('Are you sure?');"><i class="fas fa-sign-out-alt"></i><span>Logout</span></a>
            </div>
        </div>

        <!-- Page Content -->
        <div id="page-content-wrapper">
            <nav class="navbar navbar-light bg-light">
                <div class="navbar-left">
                    <img src="../assets/images/harana-logo.png" alt="Harana" class="header-logo" id="headerLogo" onerror="this.style.display='none';">
                    <span class="navbar-brand"><i class="fas fa-key me-2"></i>Change Password</span>
                </div>
                <div class="navbar-right">
                    <span class="text-muted small">
                        <i class="fas fa-calendar-alt me-1"></i><?php echo date('F j, Y'); ?>
                    </span>
                    <span class="small"><i class="fas fa-user-circle me-2"></i><?php echo htmlspecialchars($current_user['full_name'] ?? $current_user['username']); ?></span>
                </div>
            </nav>

            <div class="container-fluid p-4">
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

                <div class="row justify-content-center">
                    <div class="col-lg-8">
                        <!-- Password Change Card -->
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">
                                    <i class="fas fa-lock"></i> 
                                    Change Your Password
                                </h5>
                            </div>
                            <div class="card-body">
                                <!-- Info Box -->
                                <div class="info-box">
                                    <i class="fas fa-shield-alt"></i>
                                    <h6>Password Requirements:</h6>
                                    <ul>
                                        <li>Minimum 8 characters long</li>
                                        <li>At least one uppercase letter (A-Z)</li>
                                        <li>At least one lowercase letter (a-z)</li>
                                        <li>At least one number (0-9)</li>
                                        <li>At least one special character (@$!%*?&)</li>
                                        <li>Cannot be the same as your current password</li>
                                    </ul>
                                </div>

                                <form method="POST" id="changePasswordForm">
                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                    
                                    <!-- Current Password -->
                                    <div class="form-group">
                                        <label class="form-label">
                                            <i class="fas fa-lock me-2"></i>Current Password
                                        </label>
                                        <div class="password-field">
                                            <input type="password" 
                                                   class="form-control" 
                                                   name="current_password" 
                                                   id="current_password" 
                                                   placeholder="Enter your current password"
                                                   required>
                                            <i class="fas fa-eye password-toggle" onclick="togglePassword('current_password', this)"></i>
                                        </div>
                                    </div>

                                    <!-- New Password -->
                                    <div class="form-group">
                                        <label class="form-label">
                                            <i class="fas fa-key me-2"></i>New Password
                                        </label>
                                        <div class="password-field">
                                            <input type="password" 
                                                   class="form-control" 
                                                   name="new_password" 
                                                   id="new_password" 
                                                   placeholder="Enter new password"
                                                   required>
                                            <i class="fas fa-eye password-toggle" onclick="togglePassword('new_password', this)"></i>
                                        </div>
                                        
                                        <!-- Password Strength Meter -->
                                        <div class="password-strength">
                                            <div class="strength-bar">
                                                <div class="strength-fill" id="strengthFill"></div>
                                            </div>
                                            <div class="strength-text" id="strengthText">Enter password</div>
                                        </div>

                                        <!-- Password Requirements -->
                                        <div class="password-requirements">
                                            <div class="requirement-item" id="req-length">
                                                <i class="fa-regular fa-circle"></i>
                                                <span>At least 8 characters</span>
                                            </div>
                                            <div class="requirement-item" id="req-uppercase">
                                                <i class="fa-regular fa-circle"></i>
                                                <span>At least one uppercase letter</span>
                                            </div>
                                            <div class="requirement-item" id="req-lowercase">
                                                <i class="fa-regular fa-circle"></i>
                                                <span>At least one lowercase letter</span>
                                            </div>
                                            <div class="requirement-item" id="req-number">
                                                <i class="fa-regular fa-circle"></i>
                                                <span>At least one number</span>
                                            </div>
                                            <div class="requirement-item" id="req-special">
                                                <i class="fa-regular fa-circle"></i>
                                                <span>At least one special character (@$!%*?&)</span>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Confirm New Password -->
                                    <div class="form-group">
                                        <label class="form-label">
                                            <i class="fas fa-check-circle me-2"></i>Confirm New Password
                                        </label>
                                        <div class="password-field">
                                            <input type="password" 
                                                   class="form-control" 
                                                   name="confirm_password" 
                                                   id="confirm_password" 
                                                   placeholder="Confirm new password"
                                                   required>
                                            <i class="fas fa-eye password-toggle" onclick="togglePassword('confirm_password', this)"></i>
                                        </div>
                                        <div class="invalid-feedback" id="passwordMatchFeedback" style="display: none;">
                                            Passwords do not match
                                        </div>
                                    </div>

                                    <!-- Action Buttons -->
                                    <div class="d-flex justify-content-between align-items-center mt-4">
                                        <a href="settings.php" class="btn btn-secondary">
                                            <i class="fas fa-arrow-left me-2"></i>Back to Settings
                                        </a>
                                        <button type="submit" class="btn btn-primary" id="submitBtn">
                                            <i class="fas fa-save me-2"></i>Change Password
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <!-- Additional Security Tips -->
                        <div class="card mt-4">
                            <div class="card-header" style="background: linear-gradient(135deg, #28a745, #20c997);">
                                <h5 class="mb-0">
                                    <i class="fas fa-shield-alt me-2"></i>
                                    Security Tips
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="d-flex align-items-start mb-3">
                                            <i class="fas fa-check-circle text-success me-3 mt-1"></i>
                                            <div>
                                                <strong>Use a unique password</strong>
                                                <p class="text-muted mb-0 small">Don't reuse passwords from other websites</p>
                                            </div>
                                        </div>
                                        <div class="d-flex align-items-start mb-3">
                                            <i class="fas fa-check-circle text-success me-3 mt-1"></i>
                                            <div>
                                                <strong>Change regularly</strong>
                                                <p class="text-muted mb-0 small">Update your password every 3-6 months</p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="d-flex align-items-start mb-3">
                                            <i class="fas fa-check-circle text-success me-3 mt-1"></i>
                                            <div>
                                                <strong>Don't share</strong>
                                                <p class="text-muted mb-0 small">Never share your password with anyone</p>
                                            </div>
                                        </div>
                                        <div class="d-flex align-items-start mb-3">
                                            <i class="fas fa-check-circle text-success me-3 mt-1"></i>
                                            <div>
                                                <strong>Enable 2FA</strong>
                                                <p class="text-muted mb-0 small">Two-factor authentication adds extra security</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        // Sidebar Toggle Functionality
        const menuToggle = document.getElementById('menuToggle');
        const sidebar = document.getElementById('sidebar-wrapper');
        const headerLogo = document.getElementById('headerLogo');
        
        // Check if sidebar state is saved in localStorage
        const sidebarCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';
        
        // Apply saved state on page load
        if (sidebarCollapsed) {
            sidebar.classList.add('collapsed');
        }
        
        menuToggle.addEventListener('click', function() {
            sidebar.classList.toggle('collapsed');
            localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
        });

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

        // Password visibility toggle
        function togglePassword(fieldId, element) {
            const field = document.getElementById(fieldId);
            if (field.type === 'password') {
                field.type = 'text';
                element.classList.remove('fa-eye');
                element.classList.add('fa-eye-slash');
            } else {
                field.type = 'password';
                element.classList.remove('fa-eye-slash');
                element.classList.add('fa-eye');
            }
        }

        // Password strength checker
        function checkPasswordStrength(password) {
            const requirements = {
                length: password.length >= 8,
                uppercase: /[A-Z]/.test(password),
                lowercase: /[a-z]/.test(password),
                number: /[0-9]/.test(password),
                special: /[@$!%*?&]/.test(password)
            };

            // Update requirement icons
            for (const [req, met] of Object.entries(requirements)) {
                const element = document.getElementById(`req-${req}`);
                const icon = element.querySelector('i');
                if (met) {
                    icon.classList.remove('fa-regular', 'fa-circle');
                    icon.classList.add('fa-solid', 'fa-circle-check');
                    element.classList.add('met');
                } else {
                    icon.classList.remove('fa-solid', 'fa-circle-check');
                    icon.classList.add('fa-regular', 'fa-circle');
                    element.classList.remove('met');
                }
            }

            // Calculate strength
            const metCount = Object.values(requirements).filter(Boolean).length;
            const strengthFill = document.getElementById('strengthFill');
            const strengthText = document.getElementById('strengthText');

            strengthFill.className = 'strength-fill';
            
            if (password.length === 0) {
                strengthFill.style.width = '0%';
                strengthText.textContent = 'Enter password';
                strengthText.style.color = '#6c757d';
            } else if (metCount <= 2) {
                strengthFill.classList.add('weak');
                strengthText.textContent = 'Weak password';
                strengthText.style.color = '#dc3545';
            } else if (metCount <= 3) {
                strengthFill.classList.add('fair');
                strengthText.textContent = 'Fair password';
                strengthText.style.color = '#ffc107';
            } else if (metCount <= 4) {
                strengthFill.classList.add('good');
                strengthText.textContent = 'Good password';
                strengthText.style.color = '#17a2b8';
            } else {
                strengthFill.classList.add('strong');
                strengthText.textContent = 'Strong password';
                strengthText.style.color = '#28a745';
            }

            return requirements;
        }

        // Password match checker
        function checkPasswordMatch() {
            const newPass = document.getElementById('new_password').value;
            const confirmPass = document.getElementById('confirm_password').value;
            const feedback = document.getElementById('passwordMatchFeedback');
            const confirmField = document.getElementById('confirm_password');

            if (confirmPass.length > 0) {
                if (newPass !== confirmPass) {
                    confirmField.classList.add('is-invalid');
                    feedback.style.display = 'block';
                    return false;
                } else {
                    confirmField.classList.remove('is-invalid');
                    feedback.style.display = 'none';
                    return true;
                }
            }
            return true;
        }

        // Event listeners for password fields
        document.getElementById('new_password').addEventListener('input', function() {
            checkPasswordStrength(this.value);
            checkPasswordMatch();
        });

        document.getElementById('confirm_password').addEventListener('input', checkPasswordMatch);

        // Form validation before submit
        document.getElementById('changePasswordForm').addEventListener('submit', function(e) {
            const newPass = document.getElementById('new_password').value;
            const requirements = checkPasswordStrength(newPass);
            
            // Check if all requirements are met
            const allMet = Object.values(requirements).every(Boolean);
            
            if (!allMet) {
                e.preventDefault();
                document.getElementById('errorMessage').textContent = 'Please meet all password requirements.';
                bootstrap.Toast.getOrCreateInstance(document.getElementById('errorToast')).show();
                return;
            }
            
            if (!checkPasswordMatch()) {
                e.preventDefault();
                document.getElementById('errorMessage').textContent = 'Passwords do not match.';
                bootstrap.Toast.getOrCreateInstance(document.getElementById('errorToast')).show();
                return;
            }
        });

        // Show toasts from PHP messages
        document.addEventListener('DOMContentLoaded', function() {
            <?php if ($message): ?>
            document.getElementById('successMessage').textContent = '<?php echo addslashes($message); ?>';
            bootstrap.Toast.getOrCreateInstance(document.getElementById('successToast')).show();
            <?php endif; ?>
            
            <?php if ($error): ?>
            document.getElementById('errorMessage').textContent = '<?php echo addslashes($error); ?>';
            bootstrap.Toast.getOrCreateInstance(document.getElementById('errorToast')).show();
            <?php endif; ?>
        });
    </script>
</body>
</html>