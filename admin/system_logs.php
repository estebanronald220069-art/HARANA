<?php
// admin/system_logs.php
require_once '../includes/config.php';
require_once '../includes/db_connection.php';
require_once '../includes/security.php';
require_once '../includes/auth.php';
require_once '../includes/settings.php';
require_once '../includes/logger.php';

$auth->requireLogin();
$current_user = $auth->getCurrentUser();
if (!$current_user) { $auth->logout(); exit(); }

$db = Database::getInstance();

$pending_count = 0;
if ($current_user['role'] === 'admin') {
    $pending_count = (int)($db->getSingle("SELECT COUNT(*) as cnt FROM pending_users WHERE status='pending'")['cnt'] ?? 0);
}

$success_toast = '';
$error_toast   = '';

/* ============================================================
   POST ACTIONS — cleanup / purge
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error_toast = 'Invalid security token.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'cleanup') {
            $days = (int)Settings::get('security.log_retention_days', 90);
            $removed = Log::cleanup($days);
            Log::security('system', 'LOG_CLEANUP', "Cleaned logs older than $days days ($removed rows)");
            header('Location: system_logs.php?success=cleaned&removed=' . $removed);
            exit();
        }

        elseif ($action === 'purge_all') {
            if (($_POST['confirm_purge'] ?? '') === 'yes') {
                Log::security('system', 'LOG_PURGE_ALL', 'All logs permanently deleted by ' . $current_user['username']);
                Log::purgeAll();
                header('Location: system_logs.php?success=purged');
                exit();
            }
            $error_toast = 'Confirmation checkbox required.';
        }
    }
}

/* ============================================================
   FLASH
   ============================================================ */
if (isset($_GET['success'])) {
    if ($_GET['success'] === 'cleaned') {
        $n = (int)($_GET['removed'] ?? 0);
        $success_toast = "Cleaned $n old log entr" . ($n === 1 ? 'y' : 'ies') . '.';
    } elseif ($_GET['success'] === 'purged') {
        $success_toast = 'All logs permanently deleted.';
    }
}

/* ============================================================
   FILTERS
   ============================================================ */
$allowed_levels = ['debug','info','warning','error','security','audit'];
$level_filter    = $_GET['level']    ?? '';
$category_filter = $_GET['category'] ?? '';
$action_filter   = $_GET['action']   ?? '';
$user_filter     = $_GET['user']     ?? '';
$ip_filter       = $_GET['ip']       ?? '';
$search          = $_GET['search']   ?? '';
$date_from       = $_GET['date_from']?? '';
$date_to         = $_GET['date_to']  ?? '';

$filters = [];
if ($level_filter !== ''    && in_array($level_filter, $allowed_levels, true)) $filters['level']    = $level_filter;
if ($category_filter !== '') $filters['category'] = Security::sanitize($category_filter);
if ($action_filter !== '')   $filters['action']   = Security::sanitize($action_filter);
if ($user_filter !== '')     $filters['user']     = Security::sanitize($user_filter);
if ($ip_filter !== '')       $filters['ip']       = Security::sanitize($ip_filter);
if ($search !== '')          $filters['search']   = Security::sanitize($search);
if ($date_from !== '')       $filters['date_from']= $date_from;
if ($date_to !== '')         $filters['date_to']  = $date_to;

/* ============================================================
   PAGINATION
   ============================================================ */
$allowed_limits = [25, 50, 100, 200, 500];
$page  = max(1, (int)($_GET['page'] ?? 1));
$limit = isset($_GET['limit']) && in_array((int)$_GET['limit'], $allowed_limits, true)
            ? (int)$_GET['limit'] : 50;
$offset = ($page - 1) * $limit;

$result      = Log::query($filters, $page, $limit);
$logs        = $result['rows'];
$total_records = $result['total'];
$total_pages = max(1, (int)ceil($total_records / $limit));
if ($page > $total_pages) $page = $total_pages;

$categories = Log::categories();
$actions    = Log::actions();

/* ============================================================
   STATS FOR TOP BAR
   ============================================================ */
$count_error    = Log::count(['level' => 'error']);
$count_security = Log::count(['level' => 'security']);
$count_warning  = Log::count(['level' => 'warning']);

$csrf_token = Security::generateCSRFToken();

/* ============================================================
   HELPERS
   ============================================================ */
function level_badge($level) {
    $map = [
        'debug'    => ['#e9ecef', '#495057', 'bug'],
        'info'     => ['#cfe2ff', '#084298', 'info-circle'],
        'warning'  => ['#fff3cd', '#856404', 'exclamation-triangle'],
        'error'    => ['#f8d7da', '#842029', 'times-circle'],
        'security' => ['#e2e3e5', '#383d41', 'shield-alt'],
        'audit'    => ['#d1e7dd', '#0f5132', 'clipboard-check'],
    ];
    $b = $map[$level] ?? ['#e9ecef', '#495057', 'circle'];
    return '<span class="log-level" style="background:' . $b[0] . ';color:' . $b[1] . ';">'
         . '<i class="fas fa-' . $b[2] . '"></i> ' . ucfirst($level) . '</span>';
}

function buildLogUrl($page, $limit, array $filters) {
    $q = ['page' => $page, 'limit' => $limit];
    foreach (['level','category','action','user','ip','search','date_from','date_to'] as $k) {
        if (!empty($filters[$k])) $q[$k] = $filters[$k];
    }
    return '?' . http_build_query($q);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>System Logs - <?php echo APP_NAME; ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
<style>
/* ============================================================ */
/* BASE                                                          */
/* ============================================================ */
* { box-sizing: border-box; }
body {
    background: #f0f2f5;
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

/* ============================================================ */
/* SIDEBAR — dark theme matching dashboard.php                   */
/* ============================================================ */
#sidebar-wrapper {
    background: #1c1c1d;
    color: #fff;
    width: 250px;
    height: 100vh;
    overflow-y: auto;
    transition: width 0.3s ease;
    box-shadow: 4px 0 10px rgba(0,0,0,0.1);
    white-space: nowrap;
    flex-shrink: 0;
}
#sidebar-wrapper.collapsed { width: 70px; }
#sidebar-wrapper.collapsed .list-group-item span { display: none; }
#sidebar-wrapper.collapsed .list-group-item {
    text-align: center;
    padding: 15px 0;
    justify-content: center;
}
#sidebar-wrapper.collapsed .list-group-item i {
    margin-right: 0;
    width: 100%;
    text-align: center;
    font-size: 1.2rem;
}

#sidebar-wrapper .sidebar-heading {
    padding: 1rem 1rem .85rem;
    font-size: 1.1rem;
    font-weight: 700;
    border-bottom: 1px solid rgba(255,255,255,.1);
    background: #1c1c1d;
    color: white;
    display: flex;
    align-items: center;
    gap: 10px;
    line-height: 1.15;
}
#sidebar-wrapper .sidebar-heading .sidebar-logo {
    height: 34px;
    width: 34px;
    object-fit: contain;
    flex-shrink: 0;
}
#sidebar-wrapper .sidebar-heading .brand-text {
    display: flex;
    flex-direction: column;
    text-align: left;
    flex: 1;
    min-width: 0;
}
#sidebar-wrapper .sidebar-heading .brand-text .brand-acronym {
    font-size: 1.15rem;
    letter-spacing: .5px;
    font-weight: 800;
}
#sidebar-wrapper .sidebar-heading .brand-text .brand-full {
    font-size: .62rem;
    font-weight: 400;
    color: rgba(255,255,255,.7);
    letter-spacing: .2px;
    text-transform: uppercase;
    margin-top: 1px;
    white-space: normal;
    line-height: 1.15;
    max-width: 175px;
}
#sidebar-wrapper.collapsed .sidebar-heading .brand-text { display: none; }
#sidebar-wrapper.collapsed .sidebar-heading .sidebar-logo { display: none; }
#sidebar-wrapper.collapsed .sidebar-heading {
    justify-content: center;
    padding: 1rem 0;
    gap: 0;
}

#sidebar-wrapper .list-group-item {
    background: transparent;
    border: none;
    color: rgba(255,255,255,.9);
    padding: .8rem 1.2rem;
    font-weight: 500;
    transition: all .2s;
    font-size: .95rem;
    text-align: left;
    display: flex;
    align-items: center;
    gap: 10px;
}
#sidebar-wrapper .list-group-item i {
    width: 24px;
    text-align: center;
    font-size: 1rem;
    flex-shrink: 0;
}
#sidebar-wrapper .list-group-item:hover,
#sidebar-wrapper .list-group-item.active {
    background: rgba(255,255,255,.15);
    color: #fff;
    border-left: 4px solid #fff;
}
#sidebar-wrapper.collapsed .list-group-item:hover,
#sidebar-wrapper.collapsed .list-group-item.active {
    border-left: none;
    border-bottom: 2px solid #fff;
}
#sidebar-wrapper .list-group-item .badge-count {
    margin-left: auto;
    font-size: .65rem;
    padding: 3px 7px;
    line-height: 1;
    border-radius: 10px;
    flex-shrink: 0;
}
#sidebar-wrapper.collapsed .badge-count { display: none !important; }

.menu-toggle {
    background: transparent;
    border: none;
    color: white;
    font-size: 1.5rem;
    cursor: pointer;
    padding: 0 10px;
    flex-shrink: 0;
    transition: all .2s;
}
.menu-toggle:hover { transform: scale(1.1); }

/* ============================================================ */
/* MAIN CONTENT                                                  */
/* ============================================================ */
#page-content-wrapper {
    flex: 1;
    background: #f0f2f5;
    height: 100vh;
    overflow-y: auto;
    padding: 0;
    min-width: 0;
}

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
.navbar-left { display: flex; align-items: center; gap: 8px; }
.navbar-brand {
    font-size: 1.2rem;
    font-weight: 500;
    color: #1c1c1d !important;
    display: flex;
    align-items: center;
}
.navbar-brand i { color: #1c1c1d; font-size: 1.2rem; }
.navbar-right { display: flex; align-items: center; gap: 20px; }

.header-logo {
    height: 30px;
    width: auto;
    margin-right: 10px;
    vertical-align: middle;
    display: none;
}
#sidebar-wrapper.collapsed ~ #page-content-wrapper .header-logo { display: inline-block; }

/* ============================================================ */
/* BACK TO SETTINGS BANNER                                       */
/* ============================================================ */
.back-to-settings {
    background: #fff;
    border-radius: 10px;
    padding: 12px 18px;
    margin-bottom: 16px;
    box-shadow: 0 2px 6px rgba(0,0,0,0.06);
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 10px;
}
.back-to-settings .crumb {
    font-size: .85rem;
    color: #6c757d;
    display: flex;
    align-items: center;
    gap: 8px;
}
.back-to-settings .crumb a {
    color: #1c1c1d;
    text-decoration: none;
    font-weight: 600;
}
.back-to-settings .crumb a:hover { text-decoration: underline; }
.back-to-settings .crumb i { color: #adb5bd; font-size: .7rem; }

/* ============================================================ */
/* ACTION TOOLBAR + SEARCH                                       */
/* ============================================================ */
.action-toolbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    flex-wrap: wrap;
    gap: 15px;
    position: sticky;
    top: 60px;
    background: #f0f2f5;
    z-index: 99;
    padding-top: 10px;
    padding-bottom: 10px;
}
.action-buttons-group { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
.search-group {
    display: flex;
    gap: 10px;
    align-items: center;
    flex: 1;
    max-width: 500px;
}
.search-group form { width: 100%; }
.search-group .search-input {
    flex: 1;
    height: 38px;
    border: 1px solid #dee2e6;
    border-radius: 8px;
    padding: 8px 15px;
    font-size: 14px;
    transition: all 0.3s;
}
.search-group .search-input:focus {
    border-color: #1c1c1d;
    outline: none;
    box-shadow: 0 0 0 2px rgba(28,28,29,0.1);
}
.search-group .btn-search {
    height: 38px;
    padding: 0 18px;
    background: #1c1c1d;
    color: white;
    border: none;
    border-radius: 8px;
    transition: all 0.3s;
}
.search-group .btn-search:hover {
    background: #2c2c2d;
    transform: translateY(-1px);
}

/* Dark primary buttons */
.btn-primary {
    background: #1c1c1d;
    border-color: #1c1c1d;
}
.btn-primary:hover,
.btn-primary:focus,
.btn-primary:active {
    background: #2c2c2d !important;
    border-color: #2c2c2d !important;
}
.btn-outline-primary {
    color: #1c1c1d;
    border-color: #1c1c1d;
}
.btn-outline-primary:hover {
    background: #1c1c1d;
    border-color: #1c1c1d;
    color: #fff;
}

/* Filter bar */
.filter-bar {
    background: white;
    border-radius: 10px;
    padding: 15px 20px;
    margin-bottom: 20px;
    box-shadow: 0 2px 6px rgba(0,0,0,0.06);
}
.filter-row {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    align-items: flex-end;
}
.filter-item {
    flex: 1;
    min-width: 120px;
}
.filter-item label {
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    color: #6c757d;
    margin-bottom: 4px;
    display: block;
    letter-spacing: 0.5px;
}
.filter-item .form-control,
.filter-item .form-select {
    height: 36px;
    font-size: 13px;
    border-radius: 6px;
    border: 1px solid #dee2e6;
    padding: 5px 10px;
}
.filter-item .form-control:focus,
.filter-item .form-select:focus {
    border-color: #1c1c1d;
    outline: none;
    box-shadow: 0 0 0 2px rgba(28,28,29,0.1);
}
.filter-actions {
    display: flex;
    gap: 8px;
}
.filter-actions .btn {
    height: 36px;
    padding: 0 16px;
    font-size: 13px;
    border-radius: 6px;
}
.filter-actions .btn-primary { background: #1c1c1d; border: none; }
.filter-actions .btn-primary:hover { background: #2c2c2d; }
.filter-actions .btn-secondary { background: #6c757d; border: none; }
.filter-actions .btn-secondary:hover { background: #5a6268; }

/* Cards + tables */
.card {
    border: none;
    border-radius: 10px;
    box-shadow: 0 2px 6px rgba(0,0,0,0.06);
    background: white;
    margin-bottom: 20px;
}
.table { margin-bottom: 0; width: 100%; font-size: 13px; }
.table thead th {
    background: #f8f9fa;
    padding: 12px 12px;
    font-weight: 600;
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #495057;
    border-bottom: 2px solid #dee2e6;
    white-space: nowrap;
}
.table tbody td {
    padding: 10px 12px;
    vertical-align: middle;
    border-bottom: 1px solid #e9ecef;
}
.table tbody tr:hover {
    background-color: rgba(28,28,29,0.04);
}

/* Pagination */
.pagination-wrapper {
    background: white;
    padding: 15px 20px;
    border-top: 1px solid #e9ecef;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 15px;
}
.pagination-info { font-size: 13px; color: #6c757d; }
.pagination { margin: 0; gap: 4px; }
.pagination .page-link {
    color: #1c1c1d;
    padding: 6px 12px;
    font-size: 13px;
    border-radius: 6px;
    border: 1px solid #dee2e6;
    transition: all 0.2s;
}
.pagination .page-link:hover {
    background-color: #1c1c1d;
    color: #fff;
    border-color: #1c1c1d;
    transform: translateY(-1px);
}
.pagination .page-item.active .page-link {
    background-color: #1c1c1d;
    border-color: #1c1c1d;
    color: white;
}
.pagination .page-item.disabled .page-link {
    color: #adb5bd;
    cursor: not-allowed;
    transform: none;
    background: #f8f9fa;
}

/* Log-specific bits */
.log-level {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 3px 10px; border-radius: 12px;
    font-size: .68rem; font-weight: 700;
    text-transform: uppercase; letter-spacing: .3px;
}
.log-level i { font-size: .7rem; }

.log-category {
    display: inline-block; padding: 2px 8px; border-radius: 6px;
    font-size: .68rem; font-weight: 600;
    background: #e3f2fd; color: #0d47a1;
    text-transform: lowercase;
}

.log-action {
    font-family: monospace; font-size: .7rem;
    background: #f1f3f5; color: #495057;
    padding: 2px 8px; border-radius: 6px;
    font-weight: 600;
}

.log-message {
    font-size: .8rem; color: #495057;
    max-width: 380px;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

.stat-pill {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 8px 16px; border-radius: 10px;
    background: #fff; box-shadow: 0 2px 6px rgba(0,0,0,.05);
    font-size: .82rem; font-weight: 500;
}
.stat-pill i { font-size: 1rem; }
.stat-error    { color: #842029; }
.stat-security { color: #383d41; }
.stat-warning  { color: #856404; }

.stat-row { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 16px; }

.log-context {
    background: #f8f9fc; border-radius: 8px; padding: 12px;
    font-family: monospace; font-size: .75rem;
    white-space: pre-wrap; word-break: break-all;
    max-height: 400px; overflow-y: auto;
}

.row-error    { background: rgba(220,53,69,0.03); }
.row-security { background: rgba(108,117,125,0.05); }
.row-warning  { background: rgba(255,193,7,0.05); }

/* Modal dark header */
.modal-header.bg-dark {
    background: #1c1c1d !important;
    color: #fff !important;
}

/* Responsive */
@media (max-width: 768px) {
    #sidebar-wrapper {
        position: fixed;
        left: 0; top: 0;
        transform: translateX(-100%);
        z-index: 1050;
        transition: transform .3s ease;
    }
    #sidebar-wrapper.mobile-open { transform: translateX(0); }
    #sidebar-wrapper .sidebar-heading .brand-text .brand-full { display: block; }
    #sidebar-wrapper .sidebar-heading .sidebar-logo { display: block; }
    .header-logo { display: inline-block !important; }
}
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
            <a href="dashboard.php" class="list-group-item"><i class="fas fa-tachometer-alt"></i><span>Dashboard</span></a>
            <a href="members.php" class="list-group-item"><i class="fas fa-users"></i><span>Members</span></a>
            <a href="manage_chapters.php" class="list-group-item"><i class="fas fa-sitemap"></i><span>Chapters &amp; Groups</span></a>
            <a href="council.php" class="list-group-item"><i class="fas fa-user-tie"></i><span>Council</span></a>
            <a href="payments.php" class="list-group-item"><i class="fas fa-credit-card"></i><span>Payments</span></a>
            <a href="reports.php" class="list-group-item"><i class="fas fa-chart-bar"></i><span>Reports</span></a>
            <?php if ($current_user['role'] === 'admin'): ?>
            <a href="pending_users.php" class="list-group-item">
                <i class="fas fa-user-clock"></i><span>Pending</span>
                <?php if ($pending_count > 0): ?>
                    <span class="badge bg-danger badge-count"><?php echo $pending_count; ?></span>
                <?php endif; ?>
            </a>
            <?php endif; ?>
            <a href="announcements.php" class="list-group-item"><i class="fas fa-bullhorn"></i><span>Announcements</span></a>
            <a href="settings.php" class="list-group-item active">
                <i class="fas fa-cog"></i><span>Settings</span>
                <?php if ($count_error + $count_security > 0): ?>
                    <span class="badge bg-warning text-dark badge-count"><?php echo $count_error + $count_security; ?></span>
                <?php endif; ?>
            </a>
            <a href="../logout.php" class="list-group-item" onclick="return confirm('Are you sure?');"><i class="fas fa-sign-out-alt"></i><span>Logout</span></a>
        </div>
    </div>

    <!-- Page content -->
    <div id="page-content-wrapper">
        <nav class="navbar navbar-light bg-light">
            <div class="navbar-left">
                <img src="../assets/images/harana-logo.png" alt="Harana" class="header-logo" id="headerLogo" onerror="this.style.display='none';">
                <span class="navbar-brand"><i class="fas fa-clipboard-list me-2"></i>System Logs</span>
            </div>
            <div class="navbar-right">
                <span class="text-muted small"><i class="fas fa-calendar-alt me-1"></i><?php echo date('F j, Y'); ?></span>
                <span class="small"><i class="fas fa-user-circle me-2"></i><?php echo htmlspecialchars($current_user['full_name'] ?? $current_user['username']); ?></span>
            </div>
        </nav>

        <div class="container-fluid p-3">

            <!-- Toasts -->
            <div class="toast-container position-fixed top-0 end-0 p-3" style="z-index:999999;top:70px!important;">
                <div id="successToast" class="toast align-items-center text-white bg-success border-0">
                    <div class="d-flex">
                        <div class="toast-body"><i class="fas fa-check-circle me-2"></i><span id="successMessage"></span></div>
                        <button class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
                    </div>
                </div>
                <div id="errorToast" class="toast align-items-center text-white bg-danger border-0">
                    <div class="d-flex">
                        <div class="toast-body"><i class="fas fa-exclamation-circle me-2"></i><span id="errorMessage"></span></div>
                        <button class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
                    </div>
                </div>
            </div>

            <!-- Breadcrumb back to Settings -->
            <div class="back-to-settings">
                <div class="crumb">
                    <a href="settings.php"><i class="fas fa-cog me-1"></i>Settings</a>
                    <i class="fas fa-chevron-right"></i>
                    <span>System Logs</span>
                </div>
                <a href="settings.php" class="btn btn-sm btn-outline-secondary">
                    <i class="fas fa-arrow-left me-1"></i>Back to Settings
                </a>
            </div>

            <!-- Quick stats -->
            <div class="stat-row">
                <div class="stat-pill"><i class="fas fa-times-circle stat-error"></i> <?php echo number_format($count_error); ?> Errors</div>
                <div class="stat-pill"><i class="fas fa-shield-alt stat-security"></i> <?php echo number_format($count_security); ?> Security</div>
                <div class="stat-pill"><i class="fas fa-exclamation-triangle stat-warning"></i> <?php echo number_format($count_warning); ?> Warnings</div>
            </div>

            <!-- Action toolbar -->
            <div class="action-toolbar">
                <div class="search-group">
                    <form method="GET" class="d-flex w-100 gap-2">
                        <?php foreach (['level','category','action','user','ip','date_from','date_to'] as $k):
                            if (!empty($filters[$k])): ?>
                                <input type="hidden" name="<?php echo $k; ?>" value="<?php echo htmlspecialchars($filters[$k]); ?>">
                        <?php endif; endforeach; ?>
                        <input type="hidden" name="limit" value="<?php echo $limit; ?>">
                        <input type="text" name="search" class="search-input"
                               placeholder="Search message or action..."
                               value="<?php echo htmlspecialchars($search); ?>">
                        <button type="submit" class="btn-search"><i class="fas fa-search"></i> Search</button>
                    </form>
                </div>
                <div class="action-buttons-group">
                    <a href="system_logs.php" class="btn btn-outline-secondary">
                        <i class="fas fa-undo me-1"></i>Reset
                    </a>
                    <form method="POST" style="display:inline;"
                          onsubmit="return confirm('Delete logs older than <?php echo (int)Settings::get('security.log_retention_days', 90); ?> days?');">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="action" value="cleanup">
                        <button type="submit" class="btn btn-warning">
                            <i class="fas fa-broom me-1"></i>Cleanup
                        </button>
                    </form>
                    <button class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#purgeModal">
                        <i class="fas fa-trash-alt me-1"></i>Purge All
                    </button>
                </div>
            </div>

            <!-- Filter bar -->
            <div class="filter-bar">
                <form method="GET" class="filter-row">
                    <input type="hidden" name="search" value="<?php echo htmlspecialchars($search); ?>">

                    <div class="filter-item">
                        <label>Level</label>
                        <select name="level" class="form-select">
                            <option value="">All Levels</option>
                            <?php foreach (['debug','info','warning','error','security','audit'] as $lv): ?>
                                <option value="<?php echo $lv; ?>" <?php echo $level_filter === $lv ? 'selected' : ''; ?>>
                                    <?php echo ucfirst($lv); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-item">
                        <label>Category</label>
                        <select name="category" class="form-select">
                            <option value="">All Categories</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo htmlspecialchars($cat); ?>" <?php echo $category_filter === $cat ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($cat); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-item">
                        <label>Action</label>
                        <select name="action" class="form-select">
                            <option value="">All Actions</option>
                            <?php foreach ($actions as $act): ?>
                                <option value="<?php echo htmlspecialchars($act); ?>" <?php echo $action_filter === $act ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($act); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-item">
                        <label>User</label>
                        <input type="text" name="user" class="form-control"
                               placeholder="Username or ID"
                               value="<?php echo htmlspecialchars($user_filter); ?>">
                    </div>

                    <div class="filter-item">
                        <label>Date From</label>
                        <input type="date" name="date_from" class="form-control" value="<?php echo htmlspecialchars($date_from); ?>">
                    </div>

                    <div class="filter-item">
                        <label>Date To</label>
                        <input type="date" name="date_to" class="form-control" value="<?php echo htmlspecialchars($date_to); ?>">
                    </div>

                    <div class="filter-item">
                        <label>Per Page</label>
                        <select name="limit" class="form-select" onchange="this.form.submit()">
                            <?php foreach ($allowed_limits as $opt): ?>
                                <option value="<?php echo $opt; ?>" <?php echo $limit == $opt ? 'selected' : ''; ?>><?php echo $opt; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-actions">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-filter me-1"></i> Apply</button>
                        <a href="system_logs.php" class="btn btn-secondary"><i class="fas fa-undo me-1"></i> Reset</a>
                    </div>
                </form>
            </div>

            <!-- Table -->
            <div class="card shadow-sm">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0 align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th style="width:150px;">When</th>
                                    <th style="width:110px;">Level</th>
                                    <th style="width:100px;">Category</th>
                                    <th style="width:170px;">Action</th>
                                    <th>Message</th>
                                    <th style="width:130px;">User</th>
                                    <th style="width:120px;">IP</th>
                                    <th style="width:60px;"></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (empty($logs)): ?>
                                <tr><td colspan="8" class="text-center py-5">
                                    <i class="fas fa-clipboard-list fa-3x text-muted mb-3"></i>
                                    <p class="text-muted mb-0">No log entries match your filters.</p>
                                </td></tr>
                            <?php else: foreach ($logs as $l):
                                $rowClass = '';
                                if ($l['log_level'] === 'error')    $rowClass = 'row-error';
                                elseif ($l['log_level'] === 'security') $rowClass = 'row-security';
                                elseif ($l['log_level'] === 'warning')  $rowClass = 'row-warning';
                            ?>
                                <tr class="<?php echo $rowClass; ?>">
                                    <td><small><?php echo date('M d, Y H:i:s', strtotime($l['created_at'])); ?></small></td>
                                    <td><?php echo level_badge($l['log_level']); ?></td>
                                    <td><span class="log-category"><?php echo htmlspecialchars($l['category']); ?></span></td>
                                    <td><span class="log-action"><?php echo htmlspecialchars($l['action']); ?></span></td>
                                    <td>
                                        <div class="log-message" title="<?php echo htmlspecialchars($l['message'] ?? ''); ?>">
                                            <?php echo htmlspecialchars($l['message'] ?? ''); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if (!empty($l['user_name'])): ?>
                                            <small><i class="fas fa-user-circle text-muted me-1"></i><?php echo htmlspecialchars($l['user_name']); ?></small>
                                        <?php else: ?>
                                            <small class="text-muted">—</small>
                                        <?php endif; ?>
                                    </td>
                                    <td><small class="text-muted"><?php echo htmlspecialchars($l['ip_address'] ?? '—'); ?></small></td>
                                    <td>
                                        <button class="btn btn-sm btn-outline-secondary"
                                                onclick='showLogDetails(<?php echo (int)$l["log_id"]; ?>)'
                                                title="View details">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                    <div class="pagination-wrapper">
                        <div class="pagination-info">
                            Showing <?php echo $offset + 1; ?> – <?php echo min($offset + $limit, $total_records); ?>
                            of <?php echo number_format($total_records); ?> entries
                        </div>
                        <nav>
                            <ul class="pagination">
                                <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="<?php echo buildLogUrl(1, $limit, $filters); ?>"><i class="fas fa-angle-double-left"></i></a>
                                </li>
                                <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="<?php echo buildLogUrl($page - 1, $limit, $filters); ?>"><i class="fas fa-chevron-left"></i></a>
                                </li>
                                <?php
                                $start = max(1, $page - 2);
                                $end   = min($total_pages, $page + 2);
                                for ($i = $start; $i <= $end; $i++):
                                ?>
                                <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                    <a class="page-link" href="<?php echo buildLogUrl($i, $limit, $filters); ?>"><?php echo $i; ?></a>
                                </li>
                                <?php endfor; ?>
                                <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="<?php echo buildLogUrl($page + 1, $limit, $filters); ?>"><i class="fas fa-chevron-right"></i></a>
                                </li>
                                <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="<?php echo buildLogUrl($total_pages, $limit, $filters); ?>"><i class="fas fa-angle-double-right"></i></a>
                                </li>
                            </ul>
                        </nav>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Log Details Modal -->
<div class="modal fade" id="logDetailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white py-2">
                <h5 class="modal-title"><i class="fas fa-file-code me-2"></i>Log Entry Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="logDetailsBody">
                <div class="text-center p-4">
                    <div class="spinner-border text-dark"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Purge All Modal -->
<div class="modal fade" id="purgeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="action" value="purge_all">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="fas fa-trash-alt me-2"></i>Purge All Logs</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>You are about to <strong class="text-danger">permanently delete every log entry</strong>.</p>
                    <p class="mb-3">This cannot be undone. Consider using <strong>Cleanup</strong> instead (removes only old logs).</p>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="confirm_purge" value="yes" id="confirmPurge" required>
                        <label class="form-check-label text-danger fw-bold" for="confirmPurge">
                            I understand this will delete all logs
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Purge Everything</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Log data lookup for JS (built from the current page's rows) -->
<script>
window.LOG_ROWS = <?php
$map = [];
foreach ($logs as $l) {
    $map[(int)$l['log_id']] = [
        'log_id'     => (int)$l['log_id'],
        'log_level'  => $l['log_level'],
        'category'   => $l['category'],
        'action'     => $l['action'],
        'message'    => $l['message'],
        'context'    => $l['context'],
        'user_id'    => $l['user_id'],
        'user_name'  => $l['user_name'],
        'ip_address' => $l['ip_address'],
        'user_agent' => $l['user_agent'],
        'created_at' => $l['created_at'],
    ];
}
echo json_encode($map, JSON_HEX_APOS | JSON_HEX_QUOT);
?>;
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
/* Sidebar */
const sidebar = document.getElementById('sidebar-wrapper');
document.getElementById('menuToggle')?.addEventListener('click', () => {
    sidebar.classList.toggle('collapsed');
    localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
});
if (localStorage.getItem('sidebarCollapsed') === 'true') sidebar.classList.add('collapsed');

/* Toasts */
const successToast = new bootstrap.Toast(document.getElementById('successToast'));
const errorToast   = new bootstrap.Toast(document.getElementById('errorToast'));
<?php if ($success_toast): ?>
document.getElementById('successMessage').textContent = <?php echo json_encode($success_toast); ?>;
successToast.show();
<?php endif; ?>
<?php if ($error_toast): ?>
document.getElementById('errorMessage').textContent = <?php echo json_encode($error_toast); ?>;
errorToast.show();
<?php endif; ?>

/* Details modal */
function showLogDetails(logId) {
    const row = window.LOG_ROWS[logId];
    const modal = new bootstrap.Modal(document.getElementById('logDetailsModal'));
    const body  = document.getElementById('logDetailsBody');

    if (!row) {
        body.innerHTML = '<div class="alert alert-danger">Log entry not found.</div>';
        modal.show();
        return;
    }

    let ctxPretty = '(empty)';
    if (row.context) {
        try {
            const parsed = typeof row.context === 'string' ? JSON.parse(row.context) : row.context;
            ctxPretty = JSON.stringify(parsed, null, 2);
        } catch (e) {
            ctxPretty = row.context;
        }
    }

    const escape = s => String(s ?? '').replace(/[&<>"']/g, c => ({
        '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
    }[c]));

    body.innerHTML = `
        <table class="table table-sm table-borderless mb-3">
            <tr><th style="width:140px;">Log ID</th><td>#${row.log_id}</td></tr>
            <tr><th>Timestamp</th><td>${escape(row.created_at)}</td></tr>
            <tr><th>Level</th><td>${escape(row.log_level)}</td></tr>
            <tr><th>Category</th><td>${escape(row.category)}</td></tr>
            <tr><th>Action</th><td><code>${escape(row.action)}</code></td></tr>
            <tr><th>User</th><td>${escape(row.user_name || '—')}${row.user_id ? ' (#'+row.user_id+')' : ''}</td></tr>
            <tr><th>IP</th><td>${escape(row.ip_address || '—')}</td></tr>
            <tr><th>User Agent</th><td><small class="text-muted">${escape(row.user_agent || '—')}</small></td></tr>
        </table>
        <div class="mb-2"><strong>Message</strong></div>
        <div class="log-context mb-3">${escape(row.message || '(none)')}</div>
        <div class="mb-2"><strong>Context (JSON)</strong></div>
        <div class="log-context">${escape(ctxPretty)}</div>
    `;
    modal.show();
}
</script>
</body>
</html>