<?php
// user/includes/quick_actions.php
?>
<div class="dashboard-card mt-4">
    <div class="card-header" style="background: linear-gradient(135deg, #28a745, #20c997);">
        <h5><i class="fas fa-bolt me-2"></i>Quick Actions</h5>
        <i class="fas fa-rocket"></i>
    </div>
    <div class="card-body">
        <div class="quick-actions">
            <a href="payments.php" class="quick-action">
                <i class="fas fa-history"></i>
                <span>View Payments</span>
            </a>
            <a href="#" class="quick-action" data-bs-toggle="modal" data-bs-target="#receiptsModal">
                <i class="fas fa-download"></i>
                <span>Download Receipts</span>
            </a>
            <a href="#" class="quick-action" onclick="window.print()">
                <i class="fas fa-print"></i>
                <span>Print Certificate</span>
            </a>
            <a href="profile.php" class="quick-action">
                <i class="fas fa-user-edit"></i>
                <span>Update Profile</span>
            </a>
        </div>
    </div>
</div>