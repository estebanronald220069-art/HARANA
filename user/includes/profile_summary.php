<?php
// user/includes/profile_summary.php
if (!isset($member)) {
    return;
}
$initials = strtoupper(substr($member['first_name'] ?? '', 0, 1) . substr($member['last_name'] ?? '', 0, 1));
?>
<div class="dashboard-card">
    <div class="card-header">
        <h5><i class="fas fa-user-circle me-2"></i>My Profile</h5>
        <i class="fas fa-chevron-right"></i>
    </div>
    <div class="profile-card text-center p-4">
        <div class="profile-avatar">
            <?php echo $initials ?: 'U'; ?>
        </div>
        <div class="profile-name">
            <?php echo htmlspecialchars(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? '')); ?>
        </div>
        <div class="profile-code">
            <i class="fas fa-id-card me-1"></i><?php echo htmlspecialchars($member['member_code'] ?? 'N/A'); ?>
        </div>
        
        <div class="profile-info text-start mt-4">
            <div class="info-item d-flex align-items-center mb-3">
                <div class="info-icon me-3">
                    <i class="fas fa-users"></i>
                </div>
                <div>
                    <div class="info-label small text-muted">Chapter/Group</div>
                    <div class="info-value fw-bold">
                        <?php 
                        echo htmlspecialchars($member['chapter'] ?? 'Not assigned');
                        if (!empty($member['group_name'])) {
                            echo ' - ' . htmlspecialchars($member['group_name']);
                        }
                        ?>
                    </div>
                </div>
            </div>
            
            <div class="info-item d-flex align-items-center mb-3">
                <div class="info-icon me-3">
                    <i class="fas fa-calendar-alt"></i>
                </div>
                <div>
                    <div class="info-label small text-muted">Member Since</div>
                    <div class="info-value fw-bold">
                        <?php 
                        if (!empty($member['date_joined']) && $member['date_joined'] != '0000-00-00') {
                            echo date('F j, Y', strtotime($member['date_joined']));
                        } else {
                            echo 'N/A';
                        }
                        ?>
                    </div>
                </div>
            </div>
            
            <div class="info-item d-flex align-items-center">
                <div class="info-icon me-3">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div>
                    <div class="info-label small text-muted">Membership Status</div>
                    <div class="info-value">
                        <span class="badge bg-<?php echo ($member['status'] ?? 'active') == 'active' ? 'success' : 'warning'; ?>">
                            <?php echo ucfirst($member['status'] ?? 'active'); ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>
        
        <a href="profile.php" class="btn btn-primary w-100 mt-4">
            <i class="fas fa-edit me-2"></i>View Full Profile
        </a>
    </div>
</div>