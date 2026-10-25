<?php
// admin/announcements.php
require_once '../includes/config.php';
require_once '../includes/db_connection.php';
require_once '../includes/security.php';
require_once '../includes/auth.php';
require_once '../includes/user_functions.php';

$auth->requireLogin();
$auth->requireRole('admin');
$current_user = $auth->getCurrentUser();

$db = Database::getInstance();
$message = '';
$error = '';

/* ============================================================
   PLACEHOLDER FOR SPECIFIC MESSAGING
   ============================================================ */
// NOTE: You need to ensure this function exists in your user_functions.php
// It should accept an array of user_ids and insert notifications for each.
if (!function_exists('sendMessageToSpecificUsers')) {
    function sendMessageToSpecificUsers($db, $user_ids, $title, $message_text, $type) {
        // Fallback function if yours doesn't exist. 
        // This assumes you have a `notifications` table with `user_id`.
        $count = 0;
        foreach ($user_ids as $uid) {
            try {
                $db->execute(
                    "INSERT INTO notifications (user_id, title, message, type, created_at) VALUES (?, ?, ?, ?, NOW())",
                    [$uid, $title, $message_text, $type], 'isss'
                );
                $count++;
            } catch (Throwable $e) {
                // Ignore individual failures
            }
        }
        return $count;
    }
}

/* ============================================================
   PENDING BADGE
   ============================================================ */
$pending_count = 0;
if ($current_user['role'] === 'admin') {
    try {
        $pending_count = (int)($db->getSingle("SELECT COUNT(*) as cnt FROM pending_users WHERE status = 'pending'")['cnt'] ?? 0);
    } catch (Throwable $e) { $pending_count = 0; }
}

// Handle POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token';
    } else {
        $title = Security::sanitize($_POST['title'] ?? '');
        $message_text = Security::sanitize($_POST['message'] ?? '');
        $type = Security::sanitize($_POST['type'] ?? 'announcement');
        
        if (empty($title) || empty($message_text)) {
            $error = 'Please fill in all fields';
        } else {
            if (isset($_POST['send_broadcast'])) {
                // Broadcast to all
                $count = createNotificationForAll($db, $title, $message_text, $type);
                if ($count > 0) {
                    $message = "Announcement broadcasted to $count members successfully!";
                    Security::logEvent('ANNOUNCEMENT_SENT', "Broadcast sent: $title");
                } else {
                    $error = 'Failed to send announcement';
                }
            } elseif (isset($_POST['send_specific'])) {
                // Specific Members
                $user_ids = $_POST['user_ids'] ?? [];
                if (empty($user_ids)) {
                    $error = 'Please select at least one member.';
                } else {
                    $count = sendMessageToSpecificUsers($db, $user_ids, $title, $message_text, $type);
                    if ($count > 0) {
                        $message = "Message sent to $count selected member(s) successfully!";
                        Security::logEvent('MESSAGE_SENT_SPECIFIC', "Message sent to $count users: $title");
                    } else {
                        $error = 'Failed to send messages.';
                    }
                }
            }
        }
    }
}

// Fetch all active members for the specific messaging dropdown
// Adjust 'users' and 'full_name' to match your actual database schema
$all_members = [];
try {
    $all_members = $db->getAll("SELECT user_id, username, full_name FROM users WHERE is_active = 1 ORDER BY full_name ASC");
} catch (Throwable $e) {
    // Fallback if full_name doesn't exist
    try {
        $all_members = $db->getAll("SELECT user_id, username, username as full_name FROM users WHERE is_active = 1 ORDER BY username ASC");
    } catch (Throwable $e2) {}
}

$csrf_token = Security::generateCSRFToken();
$active_tab = isset($_GET['tab']) && $_GET['tab'] === 'specific' ? 'specific' : 'broadcast';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Send Announcement - <?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
    <script>
        (function() {
            try {
                if (localStorage.getItem('sidebarCollapsed') === 'true') {
                    document.documentElement.classList.add('sidebar-was-collapsed');
                }
            } catch (e) {}
        })();
    </script>

    <style>
        * { box-sizing: border-box; }
        body {
            background: #f4f7fc;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            overflow: hidden;
            margin: 0;
            padding: 0;
        }
        #wrapper { display: flex; width: 100%; height: 100vh; overflow: hidden; }

        /* SIDEBAR */
        #sidebar-wrapper {
            background: #1c1c1d;
            color: #fff;
            width: 250px;
            height: 100vh;
            overflow-y: auto;
            overflow-x: hidden;
            transition: width 0.3s ease;
            box-shadow: 4px 0 10px rgba(0,0,0,0.1);
            white-space: nowrap;
            flex-shrink: 0;
            position: relative;
            z-index: 1000;
        }
        #sidebar-wrapper.collapsed { width: 70px; }
        html.sidebar-was-collapsed #sidebar-wrapper { width: 70px; }
        html.sidebar-was-collapsed #sidebar-wrapper .sidebar-heading .brand-text,
        html.sidebar-was-collapsed #sidebar-wrapper .sidebar-heading .sidebar-logo,
        html.sidebar-was-collapsed #sidebar-wrapper .list-group-item span,
        html.sidebar-was-collapsed #sidebar-wrapper .badge-count { display: none; }
        html.sidebar-was-collapsed #sidebar-wrapper .list-group-item { text-align: center; padding: 15px 0; justify-content: center; }
        html.sidebar-was-collapsed #sidebar-wrapper .list-group-item i { margin-right: 0; width: 100%; text-align: center; font-size: 1.2rem; }
        html.sidebar-was-collapsed #sidebar-wrapper .sidebar-heading { justify-content: center; padding: 1rem 0; gap: 0; }
        html.sidebar-transitions-on #sidebar-wrapper { transition: width 0.3s ease; }

        #sidebar-wrapper .sidebar-heading {
            padding: 1rem 1rem .85rem;
            font-size: 1.1rem;
            font-weight: 700;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            background: #1c1c1d;
            color: white;
            display: flex;
            align-items: center;
            gap: 10px;
            line-height: 1.15;
            flex-shrink: 0;
            min-width: 0;
        }
        #sidebar-wrapper .sidebar-heading .sidebar-logo { height: 34px; width: 34px; object-fit: contain; flex-shrink: 0; transition: all 0.3s ease; }
        #sidebar-wrapper .sidebar-heading .brand-text { display: flex; flex-direction: column; text-align: left; flex: 1; min-width: 0; }
        #sidebar-wrapper .sidebar-heading .brand-text .brand-acronym { font-size: 1.15rem; letter-spacing: .5px; font-weight: 800; }
        #sidebar-wrapper .sidebar-heading .brand-text .brand-full { font-size: .62rem; font-weight: 400; color: rgba(255,255,255,.7); letter-spacing: .2px; text-transform: uppercase; margin-top: 1px; white-space: normal; line-height: 1.15; max-width: 175px; }

        #sidebar-wrapper .list-group { padding-bottom: 20px; }
        #sidebar-wrapper .list-group-item { background: transparent; border: none; color: rgba(255,255,255,0.9); padding: 0.8rem 1.2rem; font-weight: 500; transition: background 0.2s, color 0.2s; font-size: 0.95rem; text-align: left; display: flex; align-items: center; gap: 10px; }
        #sidebar-wrapper .list-group-item i { width: 24px; text-align: center; font-size: 1rem; flex-shrink: 0; }
        #sidebar-wrapper .list-group-item:hover, #sidebar-wrapper .list-group-item.active { background: rgba(255,255,255,0.15); color: #fff; border-left: 4px solid #fff; }
        #sidebar-wrapper.collapsed .list-group-item:hover, #sidebar-wrapper.collapsed .list-group-item.active, html.sidebar-was-collapsed #sidebar-wrapper .list-group-item:hover, html.sidebar-was-collapsed #sidebar-wrapper .list-group-item.active { border-left: none; border-bottom: 2px solid #fff; }
        #sidebar-wrapper .list-group-item .badge-count { margin-left: auto; font-size: 0.65rem; padding: 3px 7px; line-height: 1; border-radius: 10px; flex-shrink: 0; }
        .menu-toggle { background: transparent; border: none; font-size: 1.5rem; color: white; cursor: pointer; padding: 0 10px; flex-shrink: 0; transition: transform 0.2s; margin-left: auto; }
        .menu-toggle:hover { color: #fff; transform: scale(1.1); }

        /* PAGE CONTENT */
        #page-content-wrapper { flex: 1; background: #f4f7fc; height: 100vh; display: flex; flex-direction: column; overflow: hidden; min-width: 0; }
        .navbar { background: #fff !important; box-shadow: 0 2px 4px rgba(0,0,0,0.05); padding: 0.7rem 1.2rem; display: flex; align-items: center; justify-content: space-between; position: sticky; top: 0; z-index: 100; flex-shrink: 0; }
        .navbar-left { display: flex; align-items: center; }
        .navbar-brand { font-size: 1.2rem; font-weight: 500; color: #375a7f !important; display: flex; align-items: center; }
        .navbar-brand i { color: #375a7f; font-size: 1.2rem; }
        .navbar-right { display: flex; align-items: center; gap: 20px; }
        .header-logo { height: 30px; width: auto; margin-right: 10px; vertical-align: middle; transition: all 0.3s ease; display: none; }
        #sidebar-wrapper.collapsed ~ #page-content-wrapper .header-logo, html.sidebar-was-collapsed #sidebar-wrapper ~ #page-content-wrapper .header-logo { display: inline-block; }

        /* ANNOUNCEMENT UI */
        .content-container { padding: 20px; flex: 1; overflow-y: auto; }
        .card { border: none; border-radius: 10px; box-shadow: 0 2px 6px rgba(0,0,0,0.06); background: white; margin-bottom: 20px; }
        
        /* TABS */
        .nav-tabs { border-bottom: 2px solid #dee2e6; margin-bottom: 20px; }
        .nav-tabs .nav-link { border: none; color: #6c757d; font-weight: 600; padding: 12px 20px; border-bottom: 3px solid transparent; transition: all 0.2s; }
        .nav-tabs .nav-link:hover { color: #375a7f; border-bottom-color: rgba(55,90,127,0.3); }
        .nav-tabs .nav-link.active { color: #375a7f; background: transparent; border-bottom-color: #375a7f; }

        .card-header { background: #1c1c1d; color: white; border-radius: 10px 10px 0 0 !important; padding: 16px 20px; border: none; }
        .card-header h5 { font-weight: 600; margin: 0; font-size: 1.1rem; }
        .card-body { padding: 24px; }
        
        .form-label { font-weight: 600; color: #2c3e50; font-size: 0.88rem; margin-bottom: 6px; }
        .form-control, .form-select { border: 1px solid #dee2e6; border-radius: 8px; padding: 10px 12px; font-size: 0.9rem; transition: all 0.2s; }
        .form-control:focus, .form-select:focus { border-color: #375a7f; box-shadow: 0 0 0 3px rgba(55,90,127,0.1); outline: none; }
        
        .btn-send { background: #1c1c1d; color: white; border: none; padding: 12px; border-radius: 8px; font-weight: 600; width: 100%; font-size: 0.95rem; transition: all 0.2s; display: flex; align-items: center; justify-content: center; gap: 8px; }
        .btn-send:hover { background: #2c4a6b; transform: translateY(-2px); box-shadow: 0 4px 12px rgba(55,90,127,0.25); }

        .alert-info { background: #e8f0fe; border: none; border-left: 4px solid #375a7f; color: #375a7f; border-radius: 8px; padding: 12px 16px; font-size: 0.85rem; }

        /* MULTI-SELECT LIST */
        .member-select-list { max-height: 300px; overflow-y: auto; border: 1px solid #dee2e6; border-radius: 8px; padding: 10px; }
        .member-check-item { display: flex; align-items: center; padding: 8px 10px; border-radius: 6px; transition: background 0.2s; cursor: pointer; border-bottom: 1px solid #f8f9fa; }
        .member-check-item:hover { background: #f8f9fa; }
        .member-check-item:last-child { border-bottom: none; }
        .member-check-item input { margin-right: 12px; width: 18px; height: 18px; cursor: pointer; accent-color: #375a7f; }
        .member-check-item label { flex: 1; cursor: pointer; margin: 0; font-size: 0.9rem; color: #2c3e50; }
        .member-check-item small { color: #6c757d; margin-left: 8px; font-size: 0.75rem; }
        
        .search-box { position: relative; margin-bottom: 10px; }
        .search-box i { position: absolute; left: 12px; top: 13px; color: #adb5bd; }
        .search-box input { padding-left: 35px; }
    </style>
</head>
<body>
    <div id="wrapper">
        <!-- Sidebar -->
        <div id="sidebar-wrapper">
            <div class="sidebar-heading">
                <img src="../assets/images/harana-logo.png" alt="NHGL" class="sidebar-logo" onerror="this.style.display='none';">
                <span class="brand-text">
                    <span class="brand-acronym">NHGL</span>
                    <span class="brand-full">Nagkaisang Haranista sa Gintong Luzon</span>
                </span>
                <button class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></button>
            </div>
            <div class="list-group list-group-flush mt-2">
                <?php $current_page = basename($_SERVER['PHP_SELF']); ?>
                <a href="dashboard.php" class="list-group-item <?php echo $current_page == 'dashboard.php' ? 'active' : ''; ?>"><i class="fas fa-tachometer-alt"></i><span>Dashboard</span></a>
                <a href="members.php" class="list-group-item <?php echo $current_page == 'members.php' ? 'active' : ''; ?>"><i class="fas fa-users"></i><span>Members</span></a>
                <a href="manage_chapters.php" class="list-group-item <?php echo $current_page == 'manage_chapters.php' ? 'active' : ''; ?>"><i class="fas fa-sitemap"></i><span>Chapters &amp; Groups</span></a>
                <a href="leader_assignments.php" class="list-group-item <?php echo $current_page == 'leader_assignments.php' ? 'active' : ''; ?>"><i class="fas fa-user-shield"></i><span>Leader Assignment</span></a>
                <a href="council.php" class="list-group-item <?php echo $current_page == 'council.php' ? 'active' : ''; ?>"><i class="fas fa-user-tie"></i><span>Council</span></a>
                <a href="payments.php" class="list-group-item <?php echo $current_page == 'payments.php' ? 'active' : ''; ?>"><i class="fas fa-credit-card"></i><span>Payments</span></a>
                <a href="remittances.php" class="list-group-item <?php echo $current_page == 'remittances.php' ? 'active' : ''; ?>"><i class="fas fa-hand-holding-usd"></i><span>Remittances</span></a>
                <a href="reports.php" class="list-group-item <?php echo $current_page == 'reports.php' ? 'active' : ''; ?>"><i class="fas fa-chart-bar"></i><span>Reports</span></a>
                <a href="announcements.php" class="list-group-item <?php echo $current_page == 'announcements.php' ? 'active' : ''; ?>"><i class="fas fa-bullhorn"></i><span>Send Announcement</span></a>
                <?php if ($current_user['role'] === 'admin'): ?>
                <a href="pending_users.php" class="list-group-item <?php echo $current_page == 'pending_users.php' ? 'active' : ''; ?>">
                    <i class="fas fa-user-clock"></i><span>Pending</span>
                    <?php if ($pending_count > 0): ?>
                        <span class="badge bg-danger badge-count"><?php echo $pending_count; ?></span>
                    <?php endif; ?>
                </a>
                <?php endif; ?>
                <a href="settings.php" class="list-group-item <?php echo $current_page == 'settings.php' ? 'active' : ''; ?>"><i class="fas fa-cog"></i><span>Settings</span></a>
                <a href="../logout.php" class="list-group-item" onclick="return confirm('Are you sure?');"><i class="fas fa-sign-out-alt"></i><span>Logout</span></a>
            </div>
        </div>

        <!-- Main Content -->
        <div id="page-content-wrapper">
            <nav class="navbar navbar-light bg-light">
                <div class="navbar-left">
                    <img src="../assets/images/harana-logo.png" alt="Harana" class="header-logo" id="headerLogo" onerror="this.style.display='none';">
                    <span class="navbar-brand"><i class="fas fa-bullhorn me-2"></i>Messaging Center</span>
                </div>
                <div class="navbar-right">
                    <span class="text-muted small"><i class="fas fa-calendar-alt me-1"></i><?php echo date('F j, Y'); ?></span>
                    <span class="small"><i class="fas fa-user-circle me-2"></i><?php echo htmlspecialchars($current_user['full_name'] ?? $current_user['username']); ?></span>
                </div>
            </nav>

            <div class="content-container">
                
                <!-- Navigation Tabs -->
                <div class="row justify-content-center">
                    <div class="col-lg-9">
                        <ul class="nav nav-tabs">
                            <li class="nav-item">
                                <a class="nav-link <?php echo $active_tab === 'broadcast' ? 'active' : ''; ?>" href="?tab=broadcast">
                                    <i class="fas fa-bullhorn me-1"></i> Broadcast to All
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?php echo $active_tab === 'specific' ? 'active' : ''; ?>" href="?tab=specific">
                                    <i class="fas fa-paper-plane me-1"></i> Message Specific Members
                                </a>
                            </li>
                        </ul>
                    </div>
                </div>

                <div class="row justify-content-center">
                    <div class="col-lg-9">
                        
                        <!-- Alerts -->
                        <?php if ($message): ?>
                            <div class="alert alert-success alert-dismissible fade show shadow-sm">
                                <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($message); ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>
                        <?php if ($error): ?>
                            <div class="alert alert-danger alert-dismissible fade show shadow-sm">
                                <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error); ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>

                        <!-- TAB 1: BROADCAST -->
                        <?php if ($active_tab === 'broadcast'): ?>
                        <div class="card shadow-sm">
                            <div class="card-header">
                                <h5><i class="fas fa-bullhorn me-2"></i>Broadcast to All Members</h5>
                            </div>
                            <div class="card-body">
                                <form method="POST">
                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                    
                                    <div class="mb-3">
                                        <label class="form-label">Announcement Type</label>
                                        <select name="type" class="form-select" required>
                                            <option value="announcement">General Announcement</option>
                                            <option value="event">Event Announcement</option>
                                            <option value="reminder">Reminder</option>
                                            <option value="system">System Update</option>
                                        </select>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label">Title</label>
                                        <input type="text" name="title" class="form-control" required placeholder="e.g., Monthly General Assembly">
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label">Message</label>
                                        <textarea name="message" class="form-control" rows="4" required placeholder="Enter your announcement message here..."></textarea>
                                    </div>
                                    
                                    <div class="alert alert-info mb-4">
                                        <i class="fas fa-info-circle me-2"></i>
                                        This announcement will be sent to <strong>every active member</strong> in the system.
                                    </div>
                                    
                                    <button type="submit" name="send_broadcast" class="btn-send">
                                        <i class="fas fa-paper-plane"></i> Broadcast Announcement
                                    </button>
                                </form>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- TAB 2: SPECIFIC MEMBERS -->
                        <?php if ($active_tab === 'specific'): ?>
                        <div class="card shadow-sm">
                            <div class="card-header bg-primary">
                                <h5><i class="fas fa-users me-2"></i>Send Message to Specific Members</h5>
                            </div>
                            <div class="card-body">
                                <form method="POST" id="specificForm">
                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                    
                                    <div class="row">
                                        <!-- Left Side: Form Fields -->
                                        <div class="col-md-7 border-end">
                                            <div class="mb-3">
                                                <label class="form-label">Message Type</label>
                                                <select name="type" class="form-select" required>
                                                    <option value="announcement">General Announcement</option>
                                                    <option value="reminder">Reminder</option>
                                                    <option value="system">System Update</option>
                                                </select>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label class="form-label">Title</label>
                                                <input type="text" name="title" class="form-control" required placeholder="e.g., Urgent Payment Reminder">
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label class="form-label">Message</label>
                                                <textarea name="message" class="form-control" rows="5" required placeholder="Enter your message here..."></textarea>
                                            </div>

                                            <div class="alert alert-info">
                                                <i class="fas fa-info-circle me-2"></i>
                                                Only the selected members on the right will receive this notification.
                                            </div>
                                            
                                            <button type="submit" name="send_specific" class="btn-send bg-primary" style="background: #375a7f;">
                                                <i class="fas fa-paper-plane"></i> Send to Selected (<span id="selectedCount">0</span>)
                                            </button>
                                        </div>

                                        <!-- Right Side: Member Selector -->
                                        <div class="col-md-5">
                                            <label class="form-label d-flex justify-content-between align-items-center">
                                                <span>Select Recipients</span>
                                                <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" id="selectAllBtn" style="font-size:0.75rem;">Select All</button>
                                            </label>
                                            
                                            <div class="search-box">
                                                <i class="fas fa-search"></i>
                                                <input type="text" id="memberSearch" class="form-control form-control-sm" placeholder="Search member name...">
                                            </div>

                                            <div class="member-select-list">
                                                <?php if (empty($all_members)): ?>
                                                    <div class="text-muted text-center py-3">No members found in database.</div>
                                                <?php else: ?>
                                                    <?php foreach ($all_members as $m): ?>
                                                        <div class="member-check-item" data-name="<?php echo strtolower(htmlspecialchars($m['full_name'] . ' ' . $m['username'])); ?>">
                                                            <input type="checkbox" name="user_ids[]" value="<?php echo $m['user_id']; ?>" class="member-checkbox" id="user_<?php echo $m['user_id']; ?>">
                                                            <label for="user_<?php echo $m['user_id']; ?>">
                                                                <?php echo htmlspecialchars($m['full_name'] ?: $m['username']); ?>
                                                                <small>@<?php echo htmlspecialchars($m['username']); ?></small>
                                                            </label>
                                                        </div>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                        <?php endif; ?>

                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // ========================================
        // SIDEBAR TOGGLE + PERSISTENCE (no-flash)
        // ========================================
        const sidebar = document.getElementById('sidebar-wrapper');
        const menuToggle = document.getElementById('menuToggle');

        if (localStorage.getItem('sidebarCollapsed') === 'true') {
            sidebar.classList.add('collapsed');
        }
        requestAnimationFrame(() => {
            requestAnimationFrame(() => {
                document.documentElement.classList.remove('sidebar-was-collapsed');
                document.documentElement.classList.add('sidebar-transitions-on');
            });
        });
        if (menuToggle) {
            menuToggle.addEventListener('click', function() {
                sidebar.classList.toggle('collapsed');
                localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
            });
        }

        // ========================================
        // SPECIFIC MEMBERS LOGIC (Select & Search)
        // ========================================
        const checkboxes = document.querySelectorAll('.member-checkbox');
        const selectedCountSpan = document.getElementById('selectedCount');
        const selectAllBtn = document.getElementById('selectAllBtn');
        const searchInput = document.getElementById('memberSearch');
        const memberItems = document.querySelectorAll('.member-check-item');

        function updateCount() {
            const checked = document.querySelectorAll('.member-checkbox:checked').length;
            if (selectedCountSpan) selectedCountSpan.textContent = checked;
            
            // Toggle Select All button text
            if (selectAllBtn && checkboxes.length > 0) {
                const visibleCheckboxes = Array.from(checkboxes).filter(cb => cb.closest('.member-check-item').style.display !== 'none');
                const visibleChecked = visibleCheckboxes.filter(cb => cb.checked).length;
                if (visibleCheckboxes.length > 0 && visibleChecked === visibleCheckboxes.length) {
                    selectAllBtn.textContent = 'Deselect All';
                } else {
                    selectAllBtn.textContent = 'Select All';
                }
            }
        }

        if (checkboxes.length > 0) {
            checkboxes.forEach(cb => {
                cb.addEventListener('change', updateCount);
            });
            updateCount();
        }

        if (selectAllBtn) {
            selectAllBtn.addEventListener('click', function() {
                const visibleItems = Array.from(memberItems).filter(item => item.style.display !== 'none');
                const allChecked = visibleItems.every(item => item.querySelector('.member-checkbox').checked);
                
                visibleItems.forEach(item => {
                    item.querySelector('.member-checkbox').checked = !allChecked;
                });
                updateCount();
            });
        }

        if (searchInput) {
            searchInput.addEventListener('input', function() {
                const query = this.value.toLowerCase().trim();
                memberItems.forEach(item => {
                    const name = item.getAttribute('data-name');
                    if (name.includes(query)) {
                        item.style.display = 'flex';
                    } else {
                        item.style.display = 'none';
                    }
                });
                updateCount();
            });
        }

        // Prevent submission if no members selected
        const specificForm = document.getElementById('specificForm');
        if (specificForm) {
            specificForm.addEventListener('submit', function(e) {
                const checked = document.querySelectorAll('.member-checkbox:checked').length;
                if (checked === 0) {
                    e.preventDefault();
                    alert('Please select at least one member to send the message to.');
                }
            });
        }
    </script>
</body>
</html>