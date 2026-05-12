<?php
// includes/header.php - Reusable header component
// Include this file after session/auth is initialized

// Get current page title from calling file or use default
$page_title = isset($page_title) ? $page_title : (basename($_SERVER['PHP_SELF'], '.php') == 'index' ? 'Dashboard' : ucfirst(basename($_SERVER['PHP_SELF'], '.php')));

// Get user data
$current_user = $auth->getCurrentUser();
$user_full_name = $current_user['full_name'] ?? $current_user['username'] ?? 'User';

// Get user's profile photo using helper function
$profile_photo = getUserProfilePhoto($db, $current_user['user_id']);

// Get initials for fallback
$initials = '';
$name_parts = explode(' ', $user_full_name);
foreach ($name_parts as $part) {
    if (!empty($part)) {
        $initials .= strtoupper(substr($part, 0, 1));
    }
}
if (empty($initials)) {
    $initials = 'U';
}
?>

<style>
    /* Navbar Styles */
    .navbar-custom {
        background: #fff !important;
        box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        padding: 0.5rem 1.2rem;
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
        gap: 15px;
    }
    
    .navbar-brand-custom {
        font-size: 1.2rem;
        font-weight: 500;
        color: #375a7f !important;
        text-decoration: none;
        display: flex;
        align-items: center;
    }
    
    .navbar-brand-custom i {
        color: #375a7f;
        margin-right: 8px;
    }
    
    .navbar-brand-custom:hover {
        color: #2c4a6b !important;
    }
    
    /* Dashboard Title Styling - BIGGER and HIGHLIGHTED */
    .page-title {
        font-size: 1.4rem;
        font-weight: 700;
        color: #2c3e50;
        position: relative;
        padding-left: 15px;
        margin-left: 5px;
        letter-spacing: -0.3px;
    }
    
    .page-title::before {
        content: '';
        position: absolute;
        left: 0;
        top: 50%;
        transform: translateY(-50%);
        width: 4px;
        height: 24px;
        background: linear-gradient(135deg, #375a7f, #2c4a6b);
        border-radius: 2px;
    }
    
    /* Optional: Add a subtle background highlight */
    .page-title-wrapper {
        background: linear-gradient(135deg, rgba(55,90,127,0.05), rgba(44,74,107,0.02));
        padding: 6px 16px 6px 20px;
        border-radius: 30px;
    }
    
    .user-profile {
        display: flex;
        align-items: center;
        gap: 12px;
        cursor: pointer;
        padding: 5px 10px;
        border-radius: 40px;
        transition: all 0.2s ease;
        text-decoration: none;
    }
    
    .user-profile:hover {
        background: #f8f9fa;
    }
    
    .user-avatar {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        object-fit: cover;
        border: 2px solid #e9ecef;
    }
    
    .user-avatar-placeholder {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        background: linear-gradient(135deg, #375a7f, #2c4a6b);
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-weight: 600;
        font-size: 1rem;
        border: 2px solid #e9ecef;
    }
    
    .user-name {
        font-weight: 500;
        color: #2c3e50;
        font-size: 0.9rem;
    }
    
    .dropdown-menu-custom {
        min-width: 200px;
        padding: 8px 0;
        border: none;
        box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        border-radius: 8px;
    }
    
    .dropdown-item-custom {
        padding: 8px 16px;
        font-size: 0.85rem;
        transition: all 0.2s;
    }
    
    .dropdown-item-custom:hover {
        background: #f8f9fa;
        transform: translateX(2px);
    }
    
    .dropdown-item-custom i {
        width: 20px;
        margin-right: 8px;
        color: #6c757d;
    }
    
    .dropdown-divider-custom {
        margin: 6px 0;
        border-color: #e9ecef;
    }
    
    @media (max-width: 768px) {
        .user-name {
            display: none;
        }
        
        .user-profile {
            padding: 5px;
        }
        
        .page-title {
            font-size: 1.1rem;
        }
        
        .page-title::before {
            height: 18px;
        }
    }
</style>

<nav class="navbar-custom">
    <div class="navbar-left">
        <a href="<?php echo ($current_user['role'] === 'admin') ? 'dashboard.php' : '../user/dashboard.php'; ?>" class="navbar-brand-custom">
            
        </a>
        
        <!-- Highlighted Dashboard Title -->
        <div class="page-title-wrapper">
            <span class="page-title"><?php echo htmlspecialchars($page_title); ?></span>
        </div>
    </div>
    
    <div class="dropdown">
        <div class="user-profile" data-bs-toggle="dropdown" aria-expanded="false">
            <?php if ($profile_photo): ?>
                <img src="<?php echo $profile_photo; ?>" alt="Profile" class="user-avatar">
            <?php else: ?>
                <div class="user-avatar-placeholder">
                    <?php echo $initials; ?>
                </div>
            <?php endif; ?>
            <span class="user-name"><?php echo htmlspecialchars($user_full_name); ?></span>
            <i class="fas fa-chevron-down" style="font-size: 0.7rem; color: #6c757d;"></i>
        </div>
        <ul class="dropdown-menu dropdown-menu-end dropdown-menu-custom">
            <li>
                <a class="dropdown-item dropdown-item-custom" href="<?php echo ($current_user['role'] === 'admin') ? '../user/profile.php' : 'profile.php'; ?>">
                    <i class="fas fa-user-circle"></i> My Profile
                </a>
            </li>
            <?php if ($current_user['role'] === 'admin'): ?>
            <li>
                <a class="dropdown-item dropdown-item-custom" href="dashboard.php">
                    <i class="fas fa-crown"></i> Admin Panel
                </a>
            </li>
            <li>
                <a class="dropdown-item dropdown-item-custom" href="change_password.php">
                    <i class="fas fa-key"></i> Change Password
                </a>
            </li>
            <?php else: ?>
            <li>
                <a class="dropdown-item dropdown-item-custom" href="change_password.php">
                    <i class="fas fa-key"></i> Change Password
                </a>
            </li>
            <?php endif; ?>
            <li><hr class="dropdown-divider-custom"></li>
            <li>
                <a class="dropdown-item dropdown-item-custom text-danger" href="../logout.php" onclick="return confirm('Are you sure you want to logout?');">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </li>
        </ul>
    </div>
</nav>