<?php
// user/notifications.php
require_once '../includes/config.php';
require_once '../includes/db_connection.php';
require_once '../includes/security.php';
require_once '../includes/auth.php';
require_once '../includes/user_functions.php';

$auth->requireLogin();
$current_user = $auth->getCurrentUser();

$db = Database::getInstance();
$member = getUserMemberData($db, $current_user);

$user_id = $current_user['user_id'];
$message = '';
$error = '';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token';
    } else {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'mark_read' && isset($_POST['notification_id'])) {
            $notification_id = (int)$_POST['notification_id'];
            $db->execute(
                "UPDATE notifications SET is_read = 1, read_at = NOW() 
                 WHERE notification_id = ? AND user_id = ?",
                [$notification_id, $user_id], 'ii'
            );
            $message = 'Notification marked as read';
            
        } elseif ($action === 'mark_all_read') {
            $db->execute(
                "UPDATE notifications SET is_read = 1, read_at = NOW() 
                 WHERE user_id = ? AND is_read = 0",
                [$user_id], 'i'
            );
            $message = 'All notifications marked as read';
            
        } elseif ($action === 'delete' && isset($_POST['notification_id'])) {
            $notification_id = (int)$_POST['notification_id'];
            $db->execute(
                "DELETE FROM notifications WHERE notification_id = ? AND user_id = ?",
                [$notification_id, $user_id], 'ii'
            );
            $message = 'Notification deleted';
            
        } elseif ($action === 'delete_all_read') {
            $db->execute(
                "DELETE FROM notifications WHERE user_id = ? AND is_read = 1",
                [$user_id], 'i'
            );
            $message = 'All read notifications deleted';
        }
    }
}

// Get filter parameters
$filter = isset($_GET['filter']) ? Security::sanitize($_GET['filter']) : 'all';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

// Build WHERE clause
$where_conditions = ["user_id = ?"];
$params = [$user_id];
$types = 'i';

if ($filter === 'unread') {
    $where_conditions[] = "is_read = 0";
} elseif ($filter !== 'all') {
    $where_conditions[] = "type = ?";
    $params[] = $filter;
    $types .= 's';
}

$where_clause = implode(' AND ', $where_conditions);

// Get total count
$count_sql = "SELECT COUNT(*) as total FROM notifications WHERE $where_clause";
$count_result = $db->getSingle($count_sql, $params, $types);
$total_notifications = $count_result['total'] ?? 0;
$total_pages = ceil($total_notifications / $limit);

// Get notifications
$sql = "SELECT * FROM notifications 
        WHERE $where_clause 
        ORDER BY created_at DESC 
        LIMIT ? OFFSET ?";
$query_params = array_merge($params, [$limit, $offset]);
$query_types = $types . 'ii';
$notifications = $db->getAll($sql, $query_params, $query_types);

// Get unread count for badge
$unread_count = $db->getSingle(
    "SELECT COUNT(*) as total FROM notifications WHERE user_id = ? AND is_read = 0",
    [$user_id], 'i'
)['total'] ?? 0;

// Get counts by type
$type_counts = $db->getAll(
    "SELECT type, COUNT(*) as count FROM notifications 
     WHERE user_id = ? GROUP BY type",
    [$user_id], 'i'
);
$type_counts_array = [];
foreach ($type_counts as $tc) {
    $type_counts_array[$tc['type']] = $tc['count'];
}

$csrf_token = Security::generateCSRFToken();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications - <?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #375a7f;
            --secondary-color: #2c4a6b;
            --success-color: #28a745;
            --warning-color: #ffc107;
            --danger-color: #dc3545;
            --info-color: #17a2b8;
        }

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

        /* Sidebar Styles */
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

        #sidebar-wrapper.collapsed .sidebar-heading img {
            display: none;
        }

        #sidebar-wrapper .sidebar-heading {
            padding: 1.2rem 1rem;
            font-size: 1.4rem;
            font-weight: 600;
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
        }

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

        .header-logo {
            height: 30px;
            width: auto;
            margin-right: 10px;
            vertical-align: middle;
            transition: all 0.3s ease;
            display: none;
        }

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

        /* Navbar */
        .navbar {
            background: #fff !important;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            padding: 0.7rem 1.2rem;
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
        }

        .navbar-brand {
            font-size: 1.2rem;
            font-weight: 500;
            color: #375a7f !important;
            display: flex;
            align-items: center;
        }

        .navbar-brand i {
            color: #375a7f;
        }

        .navbar-right {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        /* Container */
        .notifications-container {
            padding: 20px;
            max-width: 1200px;
            margin: 0 auto;
        }

        /* Stats Row */
        .stats-row {
            display: flex;
            gap: 15px;
            margin-bottom: 25px;
            flex-wrap: wrap;
        }

        .stat-badge {
            background: white;
            border-radius: 12px;
            padding: 12px 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }

        .stat-badge-icon {
            width: 40px;
            height: 40px;
            background: rgba(55,90,127,0.1);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .stat-badge-icon i {
            font-size: 1.2rem;
            color: #375a7f;
        }

        .stat-badge-info h4 {
            margin: 0;
            font-size: 1.2rem;
            font-weight: 700;
        }

        .stat-badge-info p {
            margin: 0;
            font-size: 0.7rem;
            color: #6c757d;
        }

        /* Filter Tabs */
        .filter-tabs {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }

        .filter-tab {
            padding: 8px 20px;
            background: white;
            border-radius: 30px;
            text-decoration: none;
            color: #6c757d;
            font-size: 0.85rem;
            transition: all 0.3s;
            border: 1px solid #e9ecef;
        }

        .filter-tab:hover {
            background: #375a7f;
            color: white;
            border-color: #375a7f;
        }

        .filter-tab.active {
            background: #375a7f;
            color: white;
            border-color: #375a7f;
        }

        .filter-tab .badge {
            margin-left: 5px;
            background: rgba(0,0,0,0.1);
            color: inherit;
        }

        .filter-tab.active .badge {
            background: rgba(255,255,255,0.2);
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            justify-content: flex-end;
        }

        .action-btn {
            padding: 8px 16px;
            background: white;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            color: #6c757d;
            font-size: 0.8rem;
            text-decoration: none;
            transition: all 0.3s;
        }

        .action-btn:hover {
            background: #375a7f;
            color: white;
            border-color: #375a7f;
        }

        /* Notification List */
        .notification-list {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }

        .notification-item {
            display: flex;
            align-items: flex-start;
            padding: 18px 20px;
            border-bottom: 1px solid #e9ecef;
            transition: all 0.3s;
            text-decoration: none;
            color: inherit;
        }

        .notification-item:hover {
            background: #f8f9fa;
        }

        .notification-item.unread {
            background: rgba(55,90,127,0.02);
            border-left: 3px solid #375a7f;
        }

        .notification-icon {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
            flex-shrink: 0;
        }

        .notification-icon i {
            font-size: 1.2rem;
        }

        .notification-icon.payment { background: rgba(40,167,69,0.1); color: #28a745; }
        .notification-icon.beneficiary { background: rgba(220,53,69,0.1); color: #dc3545; }
        .notification-icon.announcement { background: rgba(55,90,127,0.1); color: #375a7f; }
        .notification-icon.account { background: rgba(23,162,184,0.1); color: #17a2b8; }
        .notification-icon.reminder { background: rgba(255,193,7,0.1); color: #ffc107; }
        .notification-icon.event { background: rgba(111,66,193,0.1); color: #6f42c1; }
        .notification-icon.system { background: rgba(108,117,125,0.1); color: #6c757d; }

        .notification-content {
            flex: 1;
        }

        .notification-title {
            font-weight: 600;
            margin-bottom: 5px;
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .notification-title span {
            font-size: 0.7rem;
        }

        .notification-message {
            font-size: 0.85rem;
            color: #6c757d;
            margin-bottom: 8px;
        }

        .notification-time {
            font-size: 0.7rem;
            color: #adb5bd;
        }

        .notification-badge {
            background: #dc3545;
            color: white;
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 0.65rem;
        }

        .notification-actions {
            display: flex;
            gap: 8px;
            margin-left: 10px;
        }

        .notification-actions button {
            background: none;
            border: none;
            color: #adb5bd;
            cursor: pointer;
            transition: color 0.3s;
        }

        .notification-actions button:hover {
            color: #375a7f;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
        }

        .empty-icon {
            font-size: 4rem;
            color: #dee2e6;
            margin-bottom: 20px;
        }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            gap: 5px;
            margin-top: 20px;
            padding: 15px;
        }

        .page-link {
            padding: 8px 12px;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            color: #375a7f;
            text-decoration: none;
            transition: all 0.2s;
        }

        .page-link:hover {
            background: #375a7f;
            color: white;
            border-color: #375a7f;
        }

        .page-link.active {
            background: #375a7f;
            color: white;
            border-color: #375a7f;
        }

        .page-link.disabled {
            color: #adb5bd;
            pointer-events: none;
        }

        @media (max-width: 768px) {
            .notification-item {
                flex-direction: column;
            }
            
            .notification-actions {
                margin-left: 60px;
                margin-top: 10px;
            }
            
            .action-buttons {
                justify-content: center;
            }
            
            .stats-row {
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <div id="wrapper">
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
                <a href="profile.php" class="list-group-item"><i class="fas fa-user"></i><span>My Profile</span></a>
                <a href="payments.php" class="list-group-item"><i class="fas fa-credit-card"></i><span>Payment History</span></a>
                <a href="beneficiary.php" class="list-group-item"><i class="fas fa-heart"></i><span>Beneficiary</span></a>
                <a href="notifications.php" class="list-group-item active"><i class="fas fa-bell"></i><span>Notifications</span>
                    <?php if ($unread_count > 0): ?>
                        <span class="badge bg-danger ms-2"><?php echo $unread_count; ?></span>
                    <?php endif; ?>
                </a>
                <a href="organization.php" class="list-group-item"><i class="fas fa-building"></i><span>Organization</span></a>
                <a href="support.php" class="list-group-item"><i class="fas fa-life-ring"></i><span>Support</span></a>
                <a href="../logout.php" class="list-group-item" onclick="return confirm('Are you sure?');"><i class="fas fa-sign-out-alt"></i><span>Logout</span></a>
            </div>
        </div>

        <!-- Main Content -->
        <div id="page-content-wrapper">
           <?php 
            $page_title = 'Notifications';
            include '../includes/header.php'; 
            ?>

            <div class="notifications-container">
                <?php if ($message): ?>
                    <div class="alert alert-success alert-dismissible fade show mb-3">
                        <i class="fas fa-check-circle me-2"></i><?php echo $message; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show mb-3">
                        <i class="fas fa-exclamation-circle me-2"></i><?php echo $error; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Stats Row -->
                <div class="stats-row">
                    <div class="stat-badge">
                        <div class="stat-badge-icon">
                            <i class="fas fa-bell"></i>
                        </div>
                        <div class="stat-badge-info">
                            <h4><?php echo $total_notifications; ?></h4>
                            <p>Total Notifications</p>
                        </div>
                    </div>
                    <div class="stat-badge">
                        <div class="stat-badge-icon">
                            <i class="fas fa-envelope-open-text"></i>
                        </div>
                        <div class="stat-badge-info">
                            <h4><?php echo $unread_count; ?></h4>
                            <p>Unread</p>
                        </div>
                    </div>
                </div>

                <!-- Filter Tabs -->
                <div class="filter-tabs">
                    <a href="?filter=all" class="filter-tab <?php echo $filter == 'all' ? 'active' : ''; ?>">
                        All <span class="badge"><?php echo $total_notifications; ?></span>
                    </a>
                    <a href="?filter=unread" class="filter-tab <?php echo $filter == 'unread' ? 'active' : ''; ?>">
                        Unread <span class="badge"><?php echo $unread_count; ?></span>
                    </a>
                    <a href="?filter=payment" class="filter-tab <?php echo $filter == 'payment' ? 'active' : ''; ?>">
                        <i class="fas fa-credit-card me-1"></i> Payments
                    </a>
                    <a href="?filter=beneficiary" class="filter-tab <?php echo $filter == 'beneficiary' ? 'active' : ''; ?>">
                        <i class="fas fa-heart me-1"></i> Beneficiary
                    </a>
                    <a href="?filter=announcement" class="filter-tab <?php echo $filter == 'announcement' ? 'active' : ''; ?>">
                        <i class="fas fa-bullhorn me-1"></i> Announcements
                    </a>
                    <a href="?filter=reminder" class="filter-tab <?php echo $filter == 'reminder' ? 'active' : ''; ?>">
                        <i class="fas fa-clock me-1"></i> Reminders
                    </a>
                </div>

                <!-- Action Buttons -->
                <div class="action-buttons">
                    <form method="POST" style="display: inline-block;">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="action" value="mark_all_read">
                        <button type="submit" class="action-btn" <?php echo $unread_count == 0 ? 'disabled' : ''; ?>>
                            <i class="fas fa-check-double me-1"></i> Mark All as Read
                        </button>
                    </form>
                    <form method="POST" style="display: inline-block;">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="action" value="delete_all_read">
                        <button type="submit" class="action-btn" onclick="return confirm('Delete all read notifications?')">
                            <i class="fas fa-trash-alt me-1"></i> Delete Read
                        </button>
                    </form>
                </div>

                <!-- Notifications List -->
                <div class="notification-list">
                    <?php if (empty($notifications)): ?>
                        <div class="empty-state">
                            <div class="empty-icon">
                                <i class="fas fa-bell-slash"></i>
                            </div>
                            <h5>No Notifications</h5>
                            <p class="text-muted">You're all caught up! New notifications will appear here.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($notifications as $notification): ?>
                            <?php
                            $icon_map = [
                                'payment' => ['icon' => 'credit-card', 'class' => 'payment'],
                                'beneficiary' => ['icon' => 'heart', 'class' => 'beneficiary'],
                                'announcement' => ['icon' => 'bullhorn', 'class' => 'announcement'],
                                'account' => ['icon' => 'user-check', 'class' => 'account'],
                                'reminder' => ['icon' => 'clock', 'class' => 'reminder'],
                                'event' => ['icon' => 'calendar-alt', 'class' => 'event'],
                                'system' => ['icon' => 'cog', 'class' => 'system']
                            ];
                            $icon_info = $icon_map[$notification['type']] ?? ['icon' => 'bell', 'class' => 'system'];
                            ?>
                            <div class="notification-item <?php echo $notification['is_read'] ? '' : 'unread'; ?>">
                                <div class="notification-icon <?php echo $icon_info['class']; ?>">
                                    <i class="fas fa-<?php echo $icon_info['icon']; ?>"></i>
                                </div>
                                <div class="notification-content">
                                    <div class="notification-title">
                                        <strong><?php echo htmlspecialchars($notification['title']); ?></strong>
                                        <?php if (!$notification['is_read']): ?>
                                            <span class="notification-badge">New</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="notification-message">
                                        <?php echo nl2br(htmlspecialchars($notification['message'])); ?>
                                    </div>
                                    <div class="notification-time">
                                        <i class="far fa-clock me-1"></i>
                                        <?php 
                                        $time = strtotime($notification['created_at']);
                                        $diff = time() - $time;
                                        if ($diff < 60) {
                                            echo 'Just now';
                                        } elseif ($diff < 3600) {
                                            echo floor($diff / 60) . ' minutes ago';
                                        } elseif ($diff < 86400) {
                                            echo floor($diff / 3600) . ' hours ago';
                                        } else {
                                            echo date('M d, Y', $time);
                                        }
                                        ?>
                                        <?php if ($notification['read_at']): ?>
                                            <span class="text-success ms-2">
                                                <i class="fas fa-check-circle"></i> Read on <?php echo date('M d, Y', strtotime($notification['read_at'])); ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($notification['link']): ?>
                                    <div class="mt-2">
                                        <a href="<?php echo htmlspecialchars($notification['link']); ?>" class="small">
                                            View Details <i class="fas fa-arrow-right ms-1"></i>
                                        </a>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <div class="notification-actions">
                                    <?php if (!$notification['is_read']): ?>
                                    <form method="POST">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                        <input type="hidden" name="action" value="mark_read">
                                        <input type="hidden" name="notification_id" value="<?php echo $notification['notification_id']; ?>">
                                        <button type="submit" title="Mark as read">
                                            <i class="fas fa-check"></i>
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                    <form method="POST" onsubmit="return confirm('Delete this notification?');">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="notification_id" value="<?php echo $notification['notification_id']; ?>">
                                        <button type="submit" title="Delete">
                                            <i class="fas fa-trash-alt"></i>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <a href="?filter=<?php echo $filter; ?>&page=1" class="page-link <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                        <i class="fas fa-angle-double-left"></i>
                    </a>
                    <a href="?filter=<?php echo $filter; ?>&page=<?php echo $page-1; ?>" class="page-link <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                        <i class="fas fa-angle-left"></i>
                    </a>
                    
                    <?php 
                    $start = max(1, $page - 2);
                    $end = min($total_pages, $page + 2);
                    for ($i = $start; $i <= $end; $i++): 
                    ?>
                    <a href="?filter=<?php echo $filter; ?>&page=<?php echo $i; ?>" class="page-link <?php echo $i == $page ? 'active' : ''; ?>">
                        <?php echo $i; ?>
                    </a>
                    <?php endfor; ?>
                    
                    <a href="?filter=<?php echo $filter; ?>&page=<?php echo $page+1; ?>" class="page-link <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                        <i class="fas fa-angle-right"></i>
                    </a>
                    <a href="?filter=<?php echo $filter; ?>&page=<?php echo $total_pages; ?>" class="page-link <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                        <i class="fas fa-angle-double-right"></i>
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Sidebar Toggle
        const menuToggle = document.getElementById('menuToggle');
        const sidebar = document.getElementById('sidebar-wrapper');
        const headerLogo = document.getElementById('headerLogo');
        
        const sidebarCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';
        
        if (sidebarCollapsed) {
            sidebar.classList.add('collapsed');
        }
        
        if (menuToggle) {
            menuToggle.addEventListener('click', function() {
                sidebar.classList.toggle('collapsed');
                localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
            });
        }

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