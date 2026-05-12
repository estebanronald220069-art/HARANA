<?php
// user/includes/user_header.php
if (!isset($current_user)) {
    require_once '../includes/auth.php';
    $current_user = $auth->getCurrentUser();
}

// Get member data if not set
if (!isset($member) && isset($db) && isset($current_user)) {
    $member = getUserMemberData($db, $current_user);
}
?>
<!-- Top Navigation -->
<nav class="top-navbar d-flex justify-content-between align-items-center">
    <div class="d-flex align-items-center">
        <button class="btn btn-link d-md-none me-3" id="sidebarToggle">
            <i class="fas fa-bars" style="color: var(--primary-color); font-size: 1.5rem;"></i>
        </button>
        <a href="dashboard.php" class="brand">
            <i class="fas fa-hand-holding-heart"></i> Harana
        </a>
    </div>
    
    <div class="dropdown">
        <div class="user-dropdown" data-bs-toggle="dropdown">
            <div class="user-avatar">
                <?php 
                $initials = 'U';
                if (isset($member) && !empty($member['first_name']) && !empty($member['last_name'])) {
                    $initials = strtoupper(substr($member['first_name'], 0, 1) . substr($member['last_name'], 0, 1));
                } elseif (isset($current_user['full_name'])) {
                    $name_parts = explode(' ', $current_user['full_name']);
                    $initials = '';
                    foreach ($name_parts as $part) {
                        if (!empty($part)) {
                            $initials .= strtoupper(substr($part, 0, 1));
                        }
                    }
                }
                echo $initials;
                ?>
            </div>
            <div class="user-info d-none d-md-block">
                <div class="user-name"><?php echo htmlspecialchars($current_user['full_name'] ?? $current_user['username']); ?></div>
                <div class="user-role"><?php echo ucfirst($current_user['role'] ?? 'Member'); ?></div>
            </div>
            <i class="fas fa-chevron-down ms-2"></i>
        </div>
        <ul class="dropdown-menu dropdown-menu-end">
            <li><a class="dropdown-item" href="profile.php"><i class="fas fa-user me-2"></i>My Profile</a></li>
            <li><a class="dropdown-item" href="../admin/settings.php"><i class="fas fa-cog me-2"></i>Settings</a></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item" href="../logout.php"><i class="fas fa-sign-out-alt me-2"></i>Logout</a></li>
        </ul>
    </div>
</nav>