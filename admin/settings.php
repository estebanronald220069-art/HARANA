<?php
// admin/settings.php
require_once '../includes/config.php';
require_once '../includes/db_connection.php';
require_once '../includes/security.php';
require_once '../includes/auth.php';

$auth->requireLogin();
$current_user = $auth->getCurrentUser();

// If current user is not found in database, log out
if (!$current_user) {
    error_log("settings.php: Current user not found, logging out");
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

// Handle 2FA setup
if (isset($_POST['setup_2fa'])) {
    if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token';
    } else {
        $result = $auth->setup2FA($current_user['user_id']);
        $message = '2FA has been enabled! Save these backup codes:';
        $backup_codes = $result['backup_codes'];
    }
}

$csrf_token = Security::generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings - <?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
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
        
        /* Fix for pending badge positioning */
        .list-group-item.position-relative {
            position: relative !important;
            overflow: visible !important;
        }

        .badge-count {
            position: absolute !important;
            top: 50% !important;
            right: 10px !important;
            left: auto !important;
            transform: translateY(-50%) !important;
            font-size: 0.7rem !important;
            padding: 3px 6px !important;
            border-radius: 10px !important;
            min-width: 20px !important;
            text-align: center !important;
            z-index: 100 !important;
        }

        /* When sidebar is collapsed */
        #sidebar-wrapper.collapsed .badge-count {
            display: none !important;
        }

        /* For the pending page itself, keep the badge visible */
        #sidebar-wrapper .list-group-item.active .badge-count {
            background-color: #dc3545 !important;
            color: white !important;
        }
        
        .card {
            border: none;
            border-radius: 10px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.06);
            background: white;
            margin-bottom: 20px;
        }
        
        .card-header {
            background: linear-gradient(135deg, #375a7f, #2c4a6b);
            color: white;
            padding: 15px 20px;
            border-radius: 10px 10px 0 0 !important;
        }
        
        .card-header h5 {
            margin: 0;
            font-size: 1rem;
        }
        
        .card-body {
            padding: 20px;
        }
        
        .btn-warning {
            background: linear-gradient(135deg, #ffc107, #d39e00);
            border: none;
            color: #212529;
            transition: all 0.3s;
        }
        
        .btn-warning:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(255, 193, 7, 0.3);
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
                <a href="pending_users.php" class="list-group-item position-relative">
                    <i class="fas fa-user-clock"></i><span>Pending</span>
                    <?php if ($pending_count > 0): ?>
                        <span class="badge bg-danger position-absolute top-0 start-100 translate-middle badge-count"><?php echo $pending_count; ?></span>
                    <?php endif; ?>
                </a>
                <?php endif; ?>
                <a href="settings.php" class="list-group-item active"><i class="fas fa-cog"></i><span>Settings</span></a>
                <a href="../logout.php" class="list-group-item" onclick="return confirm('Are you sure?');"><i class="fas fa-sign-out-alt"></i><span>Logout</span></a>
            </div>
        </div>
        
        <!-- Main Content -->
        <div class="flex-grow-1">
            <nav class="navbar navbar-light bg-light px-4 py-3">
                <span class="navbar-brand">Settings</span>
                <div class="ms-auto">
                    <span><i class="fas fa-user-circle me-2"></i><?php echo htmlspecialchars($current_user['full_name']); ?></span>
                </div>
            </nav>
            
            <div class="container-fluid p-4">
                <div class="row">
                    <!-- 2FA Card -->
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h5><i class="fas fa-shield-alt me-2"></i>Two-Factor Authentication</h5>
                            </div>
                            <div class="card-body">
                                <?php if ($message): ?>
                                    <div class="alert alert-success">
                                        <?php echo $message; ?>
                                        <?php if (isset($backup_codes)): ?>
                                            <div class="mt-3">
                                                <?php foreach ($backup_codes as $code): ?>
                                                    <code><?php echo $code; ?></code><br>
                                                <?php endforeach; ?>
                                            </div>
                                            <p class="text-danger mt-3">Save these codes! They won't be shown again.</p>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                                
                                <?php if ($error): ?>
                                    <div class="alert alert-danger"><?php echo $error; ?></div>
                                <?php endif; ?>
                                
                                <form method="POST">
                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                    <p>Enable two-factor authentication for additional security.</p>
                                    <button type="submit" name="setup_2fa" class="btn btn-primary">
                                        <i class="fas fa-qrcode me-2"></i>Enable 2FA
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Change Password Card -->
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h5><i class="fas fa-key me-2"></i>Change Password</h5>
                            </div>
                            <div class="card-body">
                                <p>Update your account password regularly for better security.</p>
                                <a href="change_password.php" class="btn btn-warning">
                                    <i class="fas fa-key me-2"></i>Change Password
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- You can add more settings here in additional rows -->
                <div class="row mt-4">
                    <div class="col-12">
                        <!-- Additional settings can go here -->
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
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
            
            // Save state to localStorage
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
    </script>
</body>
</html>