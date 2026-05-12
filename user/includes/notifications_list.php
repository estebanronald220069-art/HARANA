<?php
// user/includes/notifications_list.php
if (!isset($announcements)) {
    $announcements = getAnnouncements();
}
if (!isset($upcoming_events)) {
    $upcoming_events = getUpcomingEvents();
}
if (!isset($birthdays_this_month)) {
    $birthdays_this_month = isset($member) ? getBirthdaysThisMonth($member) : [];
}
?>
<div class="row">
    <div class="col-md-8">
        <div class="dashboard-card">
            <div class="card-header" style="background: linear-gradient(135deg, #17a2b8, #138496);">
                <h5><i class="fas fa-bullhorn me-2"></i>All Announcements</h5>
            </div>
            <div class="card-body">
                <?php if (empty($announcements)): ?>
                    <p class="text-muted text-center py-4">No announcements at this time.</p>
                <?php else: ?>
                    <?php foreach ($announcements as $announcement): ?>
                    <div class="notification-item d-flex mb-4 pb-3 border-bottom">
                        <div class="notification-icon me-3">
                            <i class="fas fa-<?php 
                                echo $announcement['type'] == 'meeting' ? 'users' : 
                                    ($announcement['type'] == 'payment' ? 'credit-card' : 
                                    ($announcement['type'] == 'event' ? 'calendar-alt' : 'bullhorn')); 
                            ?> fa-2x text-<?php 
                                echo $announcement['type'] == 'meeting' ? 'primary' : 
                                    ($announcement['type'] == 'payment' ? 'success' : 
                                    ($announcement['type'] == 'event' ? 'info' : 'warning')); 
                            ?>"></i>
                        </div>
                        <div class="notification-content flex-grow-1">
                            <div class="d-flex justify-content-between align-items-start">
                                <div class="notification-title h5">
                                    <?php echo htmlspecialchars($announcement['title']); ?>
                                </div>
                                <?php if (strtotime($announcement['date']) > time()): ?>
                                    <span class="badge bg-danger">NEW</span>
                                <?php endif; ?>
                            </div>
                            <div class="notification-text">
                                <?php echo htmlspecialchars($announcement['content']); ?>
                            </div>
                            <div class="notification-date text-muted mt-2">
                                <i class="fas fa-clock me-1"></i><?php echo date('F j, Y', strtotime($announcement['date'])); ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <div class="col-md-4">
        <div class="dashboard-card">
            <div class="card-header" style="background: linear-gradient(135deg, #ff6b6b, #ee5a24);">
                <h5><i class="fas fa-calendar-alt me-2"></i>Upcoming Events</h5>
            </div>
            <div class="card-body">
                <?php if (empty($upcoming_events)): ?>
                    <p class="text-muted text-center py-3">No upcoming events</p>
                <?php else: ?>
                    <?php foreach ($upcoming_events as $event): ?>
                    <div class="event-item d-flex align-items-center mb-3 pb-2 border-bottom">
                        <div class="event-date text-center me-3">
                            <div class="event-day fw-bold fs-4 text-primary"><?php echo date('d', strtotime($event['date'])); ?></div>
                            <div class="event-month small text-muted"><?php echo date('M', strtotime($event['date'])); ?></div>
                        </div>
                        <div class="event-details flex-grow-1">
                            <div class="event-title fw-bold"><?php echo htmlspecialchars($event['title']); ?></div>
                            <div class="event-time small text-muted">
                                <i class="fas fa-clock me-1"></i>All day
                            </div>
                        </div>
                        <span class="badge bg-<?php 
                            echo $event['type'] == 'meeting' ? 'primary' : 
                                ($event['type'] == 'payment' ? 'success' : 
                                ($event['type'] == 'event' ? 'info' : 'warning')); 
                        ?>">
                            <?php echo ucfirst($event['type']); ?>
                        </span>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="dashboard-card mt-3">
            <div class="card-header" style="background: linear-gradient(135deg, #28a745, #20c997);">
                <h5><i class="fas fa-birthday-cake me-2"></i>Birthdays This Month</h5>
            </div>
            <div class="card-body">
                <?php if (empty($birthdays_this_month)): ?>
                    <p class="text-muted text-center py-3">No birthdays this month</p>
                <?php else: ?>
                    <?php foreach ($birthdays_this_month as $bday): ?>
                    <div class="d-flex align-items-center mb-2">
                        <div class="council-avatar me-3" style="width: 40px; height: 40px; background: linear-gradient(135deg, #ff6b6b, #ee5a24);">
                            <i class="fas fa-birthday-cake"></i>
                        </div>
                        <div>
                            <div class="fw-bold"><?php echo htmlspecialchars($bday['name']); ?></div>
                            <small class="text-muted">Celebrating on <?php echo $bday['day']; ?></small>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>