<?php
// user/includes/welcome_banner.php
if (!isset($member)) {
    return;
}
?>
<div class="welcome-banner" data-aos="fade-up">
    <div class="date">
        <i class="fas fa-calendar-alt me-2"></i><?php echo date('F j, Y'); ?>
    </div>
    <h2>Welcome back, <?php echo htmlspecialchars($member['first_name'] ?? 'Member'); ?>! 👋</h2>
    <p>Here's what's happening with your membership today.</p>
</div>