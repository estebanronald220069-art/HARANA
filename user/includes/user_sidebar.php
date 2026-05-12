<?php
// user/includes/user_sidebar.php
$current_page = basename($_SERVER['PHP_SELF']);

// Get unread notification count
$unread_count = 0;
if (isset($current_user['user_id']) && isset($db)) {
    $unread_count = getUnreadNotificationCount($db, $current_user['user_id']);
}
?>
<!-- Sidebar -->
<div class="sidebar" id="sidebar">
    <ul class="sidebar-menu">
        <li class="sidebar-item">
            <a href="dashboard.php" class="sidebar-link <?php echo $current_page == 'dashboard.php' ? 'active' : ''; ?>">
                <i class="fas fa-tachometer-alt"></i>
                <span>Dashboard</span>
            </a>
        </li>
        <li class="sidebar-item">
            <a href="profile.php" class="sidebar-link <?php echo $current_page == 'profile.php' ? 'active' : ''; ?>">
                <i class="fas fa-user"></i>
                <span>My Profile</span>
            </a>
        </li>
        <li class="sidebar-item">
            <a href="payments.php" class="sidebar-link <?php echo $current_page == 'payments.php' ? 'active' : ''; ?>">
                <i class="fas fa-credit-card"></i>
                <span>Payment History</span>
                <?php 
                if (isset($total_payments) && $total_payments > 0) {
                    echo '<span class="badge bg-primary ms-auto">' . $total_payments . '</span>';
                }
                ?>
            </a>
        </li>
        <li class="sidebar-item">
            <a href="beneficiary.php" class="sidebar-link <?php echo $current_page == 'beneficiary.php' ? 'active' : ''; ?>">
                <i class="fas fa-heart"></i>
                <span>Beneficiary</span>
            </a>
        </li>
        <li class="sidebar-item">
            <a href="notifications.php" class="sidebar-link <?php echo $current_page == 'notifications.php' ? 'active' : ''; ?>">
                <i class="fas fa-bell"></i>
                <span>Notifications</span>
                <?php if ($unread_count > 0): ?>
                    <span class="badge bg-danger ms-auto"><?php echo $unread_count; ?></span>
                <?php endif; ?>
            </a>
        </li>
        <li class="sidebar-item">
            <a href="organization.php" class="sidebar-link <?php echo $current_page == 'organization.php' ? 'active' : ''; ?>">
                <i class="fas fa-building"></i>
                <span>Organization</span>
            </a>
        </li>
        <li class="sidebar-item">
            <a href="support.php" class="sidebar-link <?php echo $current_page == 'support.php' ? 'active' : ''; ?>">
                <i class="fas fa-life-ring"></i>
                <span>Support</span>
            </a>
        </li>
        <li class="sidebar-item">
            <a href="../logout.php" class="sidebar-link" onclick="return confirm('Are you sure you want to logout?');">
                <i class="fas fa-sign-out-alt" style="color: #dc3545;"></i>
                <span style="color: #dc3545;">Logout</span>
            </a>
        </li>
        <?php if (isset($is_admin) && $is_admin): ?>
        <li class="sidebar-item mt-3">
            <a href="../admin/dashboard.php" class="sidebar-link" style="border-left-color: var(--success-color);">
                <i class="fas fa-crown" style="color: var(--success-color);"></i>
                <span>Admin Panel</span>
            </a>
        </li>
        <?php endif; ?>
    </ul>
</div>