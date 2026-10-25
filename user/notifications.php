<?php
// user/notifications.php — Phone-first redesign (2026-10)
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

$csrf_token = Security::generateCSRFToken();
$is_admin = ($current_user['role'] === 'admin');
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=2.0, user-scalable=yes">
<title>Notifications — <?php echo defined('APP_NAME') ? APP_NAME : 'NHGL'; ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
/* ============================================================
   TOKENS (Matches Dashboard/Profile)
============================================================ */
:root{
    --brand-900:#1e3a5a;--brand-800:#162d47;--brand-700:#234c76;--brand-600:#2d5f92;
    --brand-500:#3d7ab8;--brand-100:#e8f0f8;--brand-50:#f4f8fc;
    --yellow-accent:#fbbf24;--yellow-dark:#b45309;--yellow-100:#fef3c7;
    --success-700:#14532d;--success-500:#16a34a;--success-100:#dcfce7;
    --danger-700:#7f1d1d;--danger-500:#dc2626;--danger-100:#fee2e2;
    --warning-700:#78350f;--warning-500:#d97706;--warning-100:#fef3c7;
    --ink-900:#1e2b3c;--ink-700:#334155;--ink-500:#64748b;--ink-400:#94a3b8;--ink-300:#cbd5e1;
    --line:#e2e8f0;--line-soft:#eef2f7;--paper:#f4f7fc;--surface:#ffffff;
    --radius-sm:10px;--radius:14px;--radius-lg:18px;
    --shadow-sm:0 2px 8px rgba(16,24,40,0.07);
    --shadow-md:0 4px 14px rgba(16,24,40,0.08);
    --shadow-lg:0 10px 26px rgba(16,24,40,0.10);
}
*{box-sizing:border-box}
html{font-size:18px}
body{
    background:var(--paper);
    font-family:'Inter',-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;
    font-size:1rem;line-height:1.6;color:var(--ink-900);
    margin:0;padding:0;min-height:100vh;
    -webkit-font-smoothing:antialiased;
}

/* ============================================================
   APP HEADER
============================================================ */
.app-header{
    background:var(--surface);
    border-bottom:1px solid var(--line);
    padding:0.85rem 1rem;
    display:flex;align-items:center;justify-content:space-between;
    gap:12px;position:sticky;top:0;z-index:50;
    box-shadow:var(--shadow-sm);
}
.app-brand{display:flex;align-items:center;gap:10px;min-width:0}
.app-brand .logo-wrap{
    width:44px;height:44px;border-radius:10px;
    background:var(--brand-900);color:#fff;
    display:flex;align-items:center;justify-content:center;
    font-weight:900;font-size:1rem;flex-shrink:0;
    overflow:hidden;
}
.app-brand .logo-wrap img{
    width:100%;height:100%;object-fit:contain;
    background:#fff;padding:4px;
}
.app-brand .brand-text{display:flex;flex-direction:column;line-height:1.15;min-width:0}
.app-brand .brand-name{font-weight:800;font-size:1rem;color:var(--ink-900)}
.app-brand .brand-sub{font-size:0.7rem;color:var(--ink-500);font-weight:500;line-height:1.2}
.header-actions{display:flex;gap:8px;flex-shrink:0}
.icon-btn{
    width:44px;height:44px;border-radius:10px;
    background:var(--paper);border:1px solid var(--line);
    color:var(--ink-700);display:inline-flex;
    align-items:center;justify-content:center;
    cursor:pointer;transition:all 0.15s;text-decoration:none;
}
.icon-btn:hover{background:var(--brand-50);border-color:var(--brand-500);color:var(--brand-700)}
.icon-btn svg{width:20px;height:20px}

/* ============================================================
   MAIN LAYOUT
============================================================ */
.main{max-width:720px;margin:0 auto;padding:1.25rem 1rem 6rem}

/* Alerts */
.alert{
    border-radius:var(--radius);padding:0.85rem 1rem;
    margin-bottom:0.9rem;font-weight:600;
    display:flex;align-items:flex-start;gap:10px;font-size:0.9rem;
    line-height:1.5;
}
.alert.alert-success{background:var(--success-100);border:2px solid #86efac;color:var(--success-700)}
.alert.alert-danger{background:var(--danger-100);border:2px solid #fca5a5;color:var(--danger-700)}

/* Filters */
.filter-tabs{
    display:flex;gap:8px;flex-wrap:wrap;margin-bottom:1rem;
}
.filter-tab{
    padding:8px 16px;background:var(--surface);border:1px solid var(--line);
    border-radius:100px;text-decoration:none;color:var(--ink-700);
    font-size:0.85rem;font-weight:700;transition:all 0.15s;
    display:inline-flex;align-items:center;gap:6px;
}
.filter-tab:hover{background:var(--brand-50);border-color:var(--brand-500)}
.filter-tab.active{background:var(--brand-900);color:#fff;border-color:var(--brand-900)}
.filter-tab .badge{
    background:rgba(0,0,0,0.1);color:inherit;padding:2px 8px;
    border-radius:10px;font-size:0.75rem;margin-left:4px;
}
.filter-tab.active .badge{background:rgba(255,255,255,0.2)}

/* Action Buttons (Mark all read) */
.action-bar{
    display:flex;justify-content:flex-end;gap:10px;margin-bottom:1rem;flex-wrap:wrap;
}
.btn-action{
    background:var(--surface);border:1px solid var(--line);
    color:var(--ink-700);padding:8px 16px;border-radius:var(--radius-sm);
    font-size:0.85rem;font-weight:700;cursor:pointer;font-family:inherit;
    display:inline-flex;align-items:center;gap:6px;transition:all 0.15s;
}
.btn-action:hover:not(:disabled){background:var(--brand-50);border-color:var(--brand-500);color:var(--brand-700)}
.btn-action:disabled{opacity:0.5;cursor:not-allowed}

/* Notifications List */
.notifications-list{
    background:var(--surface);border:1px solid var(--line);
    border-radius:var(--radius-lg);overflow:hidden;box-shadow:var(--shadow-sm);
}
.notif-item{
    padding:1.25rem 1.1rem;border-bottom:1px solid var(--line-soft);
    display:flex;gap:14px;align-items:flex-start;
    transition:background 0.15s;
    position:relative;
}
.notif-item:last-child{border-bottom:none}
.notif-item:hover{background:var(--brand-50)}
.notif-item.unread{background:var(--brand-50)}
.notif-item.unread::before{
    content:'';position:absolute;left:0;top:0;bottom:0;width:4px;
    background:var(--brand-500);border-radius:0 4px 4px 0;
}

/* Icon */
.notif-icon{
    width:44px;height:44px;border-radius:50%;
    display:flex;align-items:center;justify-content:center;
    flex-shrink:0;
}
.notif-icon svg{width:20px;height:20px}
.notif-icon.payment{background:var(--success-100);color:var(--success-700)}
.notif-icon.beneficiary{background:var(--danger-100);color:var(--danger-700)}
.notif-icon.announcement{background:var(--brand-100);color:var(--brand-700)}
.notif-icon.account{background:#e0f2fe;color:#075985}
.notif-icon.reminder{background:var(--warning-100);color:var(--warning-700)}
.notif-icon.event{background:#f3e8ff;color:#6b21a8}
.notif-icon.system{background:var(--line-soft);color:var(--ink-500)}

/* Content */
.notif-content{flex:1;min-width:0}
.notif-title{
    font-weight:800;color:var(--ink-900);font-size:0.95rem;
    margin-bottom:4px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;
}
.notif-badge{
    background:var(--danger-500);color:#fff;
    padding:2px 8px;border-radius:100px;font-size:0.65rem;font-weight:800;
    letter-spacing:0.02em;
}
.notif-message{
    font-size:0.9rem;color:var(--ink-700);line-height:1.5;margin-bottom:6px;
}
.notif-time{
    font-size:0.78rem;color:var(--ink-400);font-weight:500;
    display:flex;align-items:center;gap:6px;
}
.notif-time svg{width:14px;height:14px}
.notif-link{
    display:inline-flex;align-items:center;gap:4px;
    color:var(--brand-600);text-decoration:none;font-weight:700;
    font-size:0.82rem;margin-top:6px;
}
.notif-link:hover{text-decoration:underline}

/* Actions (Read/Delete) */
.notif-actions{
    display:flex;flex-direction:column;gap:8px;flex-shrink:0;
}
.icon-action{
    background:transparent;border:none;color:var(--ink-400);
    cursor:pointer;padding:4px;border-radius:6px;transition:all 0.15s;
    display:flex;align-items:center;justify-content:center;
}
.icon-action:hover{background:var(--surface);box-shadow:var(--shadow-sm);color:var(--brand-600)}
.icon-action.delete:hover{color:var(--danger-500)}
.icon-action svg{width:18px;height:18px}

/* Empty State */
.empty-state{
    text-align:center;padding:3rem 1rem;color:var(--ink-500);
}
.empty-state svg{
    width:48px;height:48px;color:var(--ink-300);margin-bottom:1rem;
}
.empty-state h3{
    font-size:1.1rem;font-weight:800;color:var(--ink-900);margin:0 0 4px;
}
.empty-state p{font-size:0.9rem;margin:0}

/* Pagination */
.pagination{
    display:flex;justify-content:center;gap:6px;margin-top:1.5rem;
    flex-wrap:wrap;
}
.page-link{
    padding:8px 14px;background:var(--surface);border:1px solid var(--line);
    border-radius:var(--radius-sm);color:var(--brand-600);
    text-decoration:none;font-weight:700;font-size:0.85rem;
    transition:all 0.15s;display:inline-flex;align-items:center;justify-content:center;
    min-width:40px;
}
.page-link:hover:not(.disabled){
    background:var(--brand-50);border-color:var(--brand-500);
}
.page-link.active{
    background:var(--brand-900);color:#fff;border-color:var(--brand-900);
}
.page-link.disabled{
    color:var(--ink-300);pointer-events:none;background:var(--paper);
}

/* ============================================================
   SIDEBAR DRAWER (Menu)
============================================================ */
.drawer-overlay {
    position: fixed; inset: 0; background: rgba(15,41,66,0.5);
    z-index: 1040; opacity: 0; visibility: hidden;
    transition: all 0.3s ease; backdrop-filter: blur(2px);
}
.drawer-overlay.open { opacity: 1; visibility: visible; }

.drawer {
    position: fixed; top: 0; right: -300px; width: 280px; height: 100vh;
    background: var(--surface); z-index: 1050;
    box-shadow: -5px 0 25px rgba(0,0,0,0.15);
    transition: right 0.3s ease; display: flex; flex-direction: column;
}
.drawer.open { right: 0; }

.drawer-header {
    padding: 1.25rem 1rem; border-bottom: 1px solid var(--line);
    display: flex; align-items: center; justify-content: space-between;
    background: var(--brand-50);
}
.drawer-header h3 { margin: 0; font-size: 1rem; font-weight: 800; color: var(--ink-900); }
.drawer-close {
    background: transparent; border: none; font-size: 1.25rem; color: var(--ink-500);
    cursor: pointer; padding: 4px;
}

.drawer-body { padding: 1rem; flex: 1; overflow-y: auto; display: flex; flex-direction: column; gap: 4px; }
.drawer-item {
    display: flex; align-items: center; gap: 12px; padding: 12px 16px;
    text-decoration: none; color: var(--ink-700); font-weight: 600; font-size: 0.95rem;
    border-radius: var(--radius-sm); transition: all 0.15s;
}
.drawer-item:hover { background: var(--brand-50); color: var(--brand-700); }
.drawer-item svg { width: 20px; height: 20px; color: var(--brand-600); }
.drawer-footer {
    padding: 1rem; border-top: 1px solid var(--line);
}
.btn-logout {
    display: flex; align-items: center; justify-content: center; gap: 8px;
    width: 100%; padding: 12px; background: var(--danger-100); color: var(--danger-700);
    border: none; border-radius: var(--radius-sm); font-weight: 800; font-size: 0.95rem;
    cursor: pointer; font-family: inherit; text-decoration: none;
}

/* ============================================================
   BOTTOM NAV (5 TABS)
============================================================ */
.bottom-nav{
    position:fixed;bottom:0;left:0;right:0;background:var(--surface);
    border-top:1px solid var(--line);display:flex;z-index:60;
    box-shadow:0 -4px 14px rgba(16,24,40,0.06);
}
.bottom-nav .nav-item{
    flex:1;display:flex;flex-direction:column;align-items:center;
    justify-content:center;gap:3px;padding:10px 4px 12px;text-decoration:none;
    color:var(--ink-500);font-size:0.68rem;font-weight:600;transition:all 0.2s;
    min-height:64px;position:relative;
}
.bottom-nav .nav-item:hover{color:var(--brand-600);background:var(--brand-50)}
.bottom-nav .nav-item.active{color:var(--brand-700);background:var(--brand-50)}
.bottom-nav .nav-item.active::before{
    content:'';position:absolute;top:0;left:12%;right:12%;height:3px;
    background:var(--yellow-accent);border-radius:0 0 3px 3px;
    animation:fadeIn 0.3s ease-in-out;
}
.bottom-nav .nav-item .icon{font-size:1.35rem;line-height:1;font-weight:900}
.bottom-nav .nav-item span:last-child{margin-top:2px}

@keyframes fadeIn {
    from { opacity: 0; transform: scaleX(0.5); }
    to { opacity: 1; transform: scaleX(1); }
}

/* Responsive */
@media (min-width:700px){
    .main{padding-bottom:2rem}
    .bottom-nav{
        position:static;max-width:720px;margin:0 auto 2rem;
        border-radius:var(--radius);border:1px solid var(--line);
        box-shadow:var(--shadow-sm);
    }
    .bottom-nav .nav-item{font-size:0.75rem}
}
@media (max-width:540px){
    html{font-size:17px}
    .main{padding:0.85rem 0.75rem 6rem}
    .notif-item{padding:1rem 0.85rem}
    .notif-icon{width:38px;height:38px}
    .notif-icon svg{width:18px;height:18px}
    .notif-actions{flex-direction:row;margin-top:6px}
    .app-brand .brand-sub{display:none}
    .bottom-nav .nav-item{font-size:0.65rem}
    .bottom-nav .nav-item .icon{font-size:1.25rem}
}
</style>
</head>
<body>

<!-- Drawer Overlay -->
<div class="drawer-overlay" id="drawerOverlay"></div>

<!-- Drawer Sidebar -->
<div class="drawer" id="sideDrawer">
    <div class="drawer-header">
        <h3>Menu</h3>
        <button class="drawer-close" id="closeDrawer">&times;</button>
    </div>
    <div class="drawer-body">
        <a href="beneficiary.php" class="drawer-item">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
            Beneficiary
        </a>
        <a href="organization.php" class="drawer-item">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21h18M3 7v14M21 7v14M6 7h12M6 3h12v4H6zM9 11h1M14 11h1M9 15h1M14 15h1"/></svg>
            Organization
        </a>
        <?php if ($is_admin): ?>
        <a href="../admin/dashboard.php" class="drawer-item">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 4l3 12h14l3-12-6 7-4-7-4 7-6-7z"/></svg>
            Admin Panel
        </a>
        <?php endif; ?>
    </div>
    <div class="drawer-footer">
        <a href="../logout.php" class="btn-logout" onclick="return confirm('Sign out of your account?');">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="width:18px;height:18px;"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            Sign Out
        </a>
    </div>
</div>

<!-- App Header -->
<header class="app-header">
    <div class="app-brand">
        <div class="logo-wrap">
            <img src="../assets/images/harana-logo.png" alt="NHGL"
                 onerror="this.style.display='none';this.parentNode.textContent='NH';">
        </div>
        <div class="brand-text">
            <span class="brand-name">NHGL</span>
            <span class="brand-sub">Nagkaisang Haranista</span>
        </div>
    </div>
    <div class="header-actions">
        <!-- 3 Dots Menu Button -->
        <button class="icon-btn" id="openDrawer" aria-label="Menu">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="1"/><circle cx="12" cy="5" r="1"/><circle cx="12" cy="19" r="1"/></svg>
        </button>
    </div>
</header>

<main class="main">

    <?php if ($message): ?>
        <div class="alert alert-success">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
            <span><?php echo htmlspecialchars($message); ?></span>
        </div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <span><?php echo htmlspecialchars($error); ?></span>
        </div>
    <?php endif; ?>

    <!-- Filter Tabs -->
    <div class="filter-tabs">
        <a href="?filter=all" class="filter-tab <?php echo $filter == 'all' ? 'active' : ''; ?>">
            All <span class="badge"><?php echo $total_notifications; ?></span>
        </a>
        <a href="?filter=unread" class="filter-tab <?php echo $filter == 'unread' ? 'active' : ''; ?>">
            Unread <span class="badge"><?php echo $unread_count; ?></span>
        </a>
        <a href="?filter=payment" class="filter-tab <?php echo $filter == 'payment' ? 'active' : ''; ?>">
            Payments
        </a>
        <a href="?filter=announcement" class="filter-tab <?php echo $filter == 'announcement' ? 'active' : ''; ?>">
            Announcements
        </a>
    </div>

    <!-- Action Buttons -->
    <div class="action-bar">
        <form method="POST" style="display:contents;">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="action" value="mark_all_read">
            <button type="submit" class="btn-action" <?php echo $unread_count == 0 ? 'disabled' : ''; ?>>
                Mark all read
            </button>
        </form>
        <form method="POST" style="display:contents;">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="action" value="delete_all_read">
            <button type="submit" class="btn-action" onclick="return confirm('Delete all read notifications?')">
                Delete read
            </button>
        </form>
    </div>

    <!-- Notifications List -->
    <div class="notifications-list">
        <?php if (empty($notifications)): ?>
            <div class="empty-state">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                <h3>No Notifications</h3>
                <p>You're all caught up! New notifications will appear here.</p>
            </div>
        <?php else: ?>
            <?php foreach ($notifications as $notification): ?>
                <?php
                // SVG Icons for different types
                $svgs = [
                    'payment' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>',
                    'beneficiary' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>',
                    'announcement' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>',
                    'account' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>',
                    'reminder' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>',
                    'event' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>',
                    'system' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>'
                ];
                $type = $notification['type'];
                $icon_svg = $svgs[$type] ?? $svgs['system'];
                $icon_class = isset($svgs[$type]) ? $type : 'system';

                // Relative time
                $time = strtotime($notification['created_at']);
                $diff = time() - $time;
                if ($diff < 60) {
                    $time_str = 'Just now';
                } elseif ($diff < 3600) {
                    $time_str = floor($diff / 60) . ' minutes ago';
                } elseif ($diff < 86400) {
                    $time_str = floor($diff / 3600) . ' hours ago';
                } else {
                    $time_str = date('M d, Y', $time);
                }
                ?>
                <div class="notif-item <?php echo $notification['is_read'] ? '' : 'unread'; ?>">
                    
                    <div class="notif-icon <?php echo $icon_class; ?>">
                        <?php echo $icon_svg; ?>
                    </div>
                    
                    <div class="notif-content">
                        <div class="notif-title">
                            <?php echo htmlspecialchars($notification['title']); ?>
                            <?php if (!$notification['is_read']): ?>
                                <span class="notif-badge">NEW</span>
                            <?php endif; ?>
                        </div>
                        <div class="notif-message">
                            <?php echo nl2br(htmlspecialchars($notification['message'])); ?>
                        </div>
                        <div class="notif-time">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                            <?php echo $time_str; ?>
                        </div>
                        <?php if ($notification['link']): ?>
                            <a href="<?php echo htmlspecialchars($notification['link']); ?>" class="notif-link">
                                View Details <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                            </a>
                        <?php endif; ?>
                    </div>

                    <div class="notif-actions">
                        <?php if (!$notification['is_read']): ?>
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                            <input type="hidden" name="action" value="mark_read">
                            <input type="hidden" name="notification_id" value="<?php echo $notification['notification_id']; ?>">
                            <button type="submit" class="icon-action" title="Mark as read">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                            </button>
                        </form>
                        <?php endif; ?>
                        <form method="POST" onsubmit="return confirm('Delete this notification?');">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="notification_id" value="<?php echo $notification['notification_id']; ?>">
                            <button type="submit" class="icon-action delete" title="Delete">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>
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
        <a href="?filter=<?php echo $filter; ?>&page=1" class="page-link <?php echo $page <= 1 ? 'disabled' : ''; ?>">&laquo;</a>
        <a href="?filter=<?php echo $filter; ?>&page=<?php echo $page-1; ?>" class="page-link <?php echo $page <= 1 ? 'disabled' : ''; ?>">&lsaquo;</a>
        
        <?php 
        $start = max(1, $page - 2);
        $end = min($total_pages, $page + 2);
        for ($i = $start; $i <= $end; $i++): 
        ?>
        <a href="?filter=<?php echo $filter; ?>&page=<?php echo $i; ?>" class="page-link <?php echo $i == $page ? 'active' : ''; ?>">
            <?php echo $i; ?>
        </a>
        <?php endfor; ?>
        
        <a href="?filter=<?php echo $filter; ?>&page=<?php echo $page+1; ?>" class="page-link <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">&rsaquo;</a>
        <a href="?filter=<?php echo $filter; ?>&page=<?php echo $total_pages; ?>" class="page-link <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">&raquo;</a>
    </div>
    <?php endif; ?>

</main>

<!-- Bottom Nav (5 Tabs) -->
<nav class="bottom-nav">
    <a href="dashboard.php" class="nav-item">
        <span class="icon">🏠</span>
        <span>Home</span>
    </a>
    <a href="notifications.php" class="nav-item active">
        <span class="icon">🔔</span>
        <span>Notify</span>
    </a>
    <a href="payments.php" class="nav-item">
        <span class="icon">💳</span>
        <span>Payments</span>
    </a>
    <a href="profile.php" class="nav-item">
        <span class="icon">👤</span>
        <span>Profile</span>
    </a>
    <a href="support.php" class="nav-item">
        <span class="icon">❓</span>
        <span>Help</span>
    </a>
</nav>

<script>
// Drawer Logic
(function(){
    const openBtn = document.getElementById('openDrawer');
    const closeBtn = document.getElementById('closeDrawer');
    const drawer = document.getElementById('sideDrawer');
    const overlay = document.getElementById('drawerOverlay');

    function openDrawer() {
        drawer.classList.add('open');
        overlay.classList.add('open');
    }

    function closeDrawer() {
        drawer.classList.remove('open');
        overlay.classList.remove('open');
    }

    openBtn.addEventListener('click', openDrawer);
    closeBtn.addEventListener('click', closeDrawer);
    overlay.addEventListener('click', closeDrawer);
})();
</script>

</body>
</html>