<?php
// user/includes/recent_announcements.php
if (!isset($announcements, $birthdays_this_month)) {
    return;
}
?>
<div class="dashboard-card mt-4">
    <div class="card-header" style="background: linear-gradient(135deg, #17a2b8, #138496);">
        <h5><i class="fas fa-bell me-2"></i>Notifications & Announcements</h5>
        <i class="fas fa-bullhorn"></i>
    </div>
    <div class="card-body">
        <?php if (!empty($birthdays_this_month)): ?>
            <div class="alert alert-success">
                <i class="fas fa-birthday-cake me-2"></i>
                <strong>Happy Birthday!</strong> 
                <?php foreach ($birthdays_this_month as $bday): ?>
                    <?php echo htmlspecialchars($bday['name']); ?> on <?php echo $bday['day']; ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        
        <?php foreach (array_slice($announcements, 0, 3) as $announcement): ?>
        <div class="notification-item d-flex mb-3">
            <div class="notification-icon me-3">
                <i class="fas fa-<?php 
                    echo $announcement['type'] == 'meeting' ? 'users' : 
                        ($announcement['type'] == 'payment' ? 'credit-card' : 
                        ($announcement['type'] == 'event' ? 'calendar-alt' : 'bullhorn')); 
                ?>"></i>
            </div>
            <div class="notification-content flex-grow-1">
                <div class="notification-title fw-bold">
                    <?php echo htmlspecialchars($announcement['title']); ?>
                    <?php if (strtotime($announcement['date']) > time()): ?>
                        <span class="badge bg-danger ms-2">NEW</span>
                    <?php endif; ?>
                </div>
                <div class="notification-text small text-muted">
                    <?php echo htmlspecialchars($announcement['content']); ?>
                </div>
                <div class="notification-date small text-muted mt-1">
                    <i class="fas fa-clock me-1"></i><?php echo date('M d, Y', strtotime($announcement['date'])); ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        
        <div class="text-center mt-3">
            <a href="notifications.php" class="btn btn-outline-info btn-sm">
                <i class="fas fa-bell me-2"></i>View All Notifications
            </a>
        </div>
    </div>
</div>