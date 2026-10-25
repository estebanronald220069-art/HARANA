<?php
// admin/rejections_list.php
require_once '../includes/config.php';
require_once '../includes/db_connection.php';
require_once '../includes/security.php';
require_once '../includes/auth.php';
require_once '../includes/user_functions.php';
require_once '../includes/rejection_reasons.php';
require_once '../includes/rejection_reasons_photos.php';
require_once '../includes/notifier.php';

$auth->requireLogin();
$auth->requireRole('admin');
$current_user = $auth->getCurrentUser();

$db = Database::getInstance();

/* ============================================================
   COUNTS
   ============================================================ */
$pending_count = 0;
$user_rejection_count  = 0;
$photo_rejection_count = 0;
$total_rejection_count = 0;

try {
    $pending_count         = (int)($db->getSingle("SELECT COUNT(*) as cnt FROM pending_users WHERE status='pending'")['cnt'] ?? 0);
    $user_rejection_count  = (int)($db->getSingle("SELECT COUNT(*) as cnt FROM pending_user_rejections WHERE rejection_type = 'user'")['cnt'] ?? 0);
    $photo_rejection_count = (int)($db->getSingle("SELECT COUNT(*) as cnt FROM pending_user_rejections WHERE rejection_type = 'photo'")['cnt'] ?? 0);
    $total_rejection_count = $user_rejection_count + $photo_rejection_count;
} catch (Throwable $e) {
    try {
        $total_rejection_count = (int)($db->getSingle("SELECT COUNT(*) as cnt FROM pending_user_rejections")['cnt'] ?? 0);
        $user_rejection_count  = $total_rejection_count;
    } catch (Throwable $e2) {}
}

/* ============================================================
   FLASH
   ============================================================ */
$success_toast = '';
$error_toast   = '';

if (isset($_GET['success'])) {
    $map = [
        'resent'         => 'Notification resent successfully.',
        'deleted'        => 'Rejection record deleted.',
        'resend_partial' => 'Some channels were resent successfully, others failed.',
    ];
    $success_toast = $map[$_GET['success']] ?? '';
}
if (isset($_GET['error'])) {
    $error_toast = Security::sanitize($_GET['error']);
}

/* ============================================================
   POST — RESEND / DELETE
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error_toast = 'Invalid security token.';
    } else {
        $action       = $_POST['action']       ?? '';
        $rejection_id = (int)($_POST['rejection_id'] ?? 0);

        /* ---------- RESEND ---------- */
        if ($action === 'resend' && $rejection_id > 0) {
            $row = $db->getSingle("SELECT * FROM pending_user_rejections WHERE id = ?", [$rejection_id], 'i');

            if (!$row) {
                $error_toast = 'Rejection record not found.';
            } else {
                $pending = [
                    'first_name'     => explode(' ', $row['full_name'])[0] ?? '',
                    'last_name'      => '',
                    'email'          => $row['email'],
                    'contact_number' => $row['contact_number'],
                    'username'       => $row['username'],
                    'member_code'    => $row['member_code'],
                ];

                $parts = preg_split('/\s+/', trim((string)$row['full_name']));
                if (count($parts) === 1) {
                    $pending['first_name'] = $parts[0];
                    $pending['last_name']  = '';
                } elseif (count($parts) >= 2) {
                    $pending['first_name'] = array_shift($parts);
                    $pending['last_name']  = implode(' ', $parts);
                }

                $rejection = [
                    'reason_code'    => $row['reason_code'],
                    'reason_label'   => $row['reason_label'],
                    'reason_message' => $row['reason_message'],
                    'custom_reason'  => $row['custom_reason'] ?? '',
                ];

                try {
                    $result = Notifier::sendRejection($pending, $rejection);
                } catch (Throwable $e) {
                    $result = [
                        'overall'      => 'failed',
                        'notification' => ['status' => 'failed', 'error' => $e->getMessage()],
                        'email'        => ['status' => 'failed', 'error' => $e->getMessage()],
                        'sms'          => ['status' => 'failed', 'error' => $e->getMessage()],
                        'delivery_log' => 'EXCEPTION: ' . $e->getMessage(),
                    ];
                }

                $db->execute(
                    "UPDATE pending_user_rejections
                     SET notification_status  = ?,
                         email_status         = ?,
                         sms_status           = ?,
                         notification_sent_at = " . ($result['notification']['status'] === 'sent' ? 'NOW()' : 'NULL') . ",
                         email_sent_at        = " . ($result['email']['status']        === 'sent' ? 'NOW()' : 'NULL') . ",
                         sms_sent_at          = " . ($result['sms']['status']          === 'sent' ? 'NOW()' : 'NULL') . ",
                         delivery_log         = CONCAT(IFNULL(delivery_log,''), '\n[RESEND " . date('Y-m-d H:i') . "] ', ?)
                     WHERE id = ?",
                    [
                        $result['notification']['status'],
                        $result['email']['status'],
                        $result['sms']['status'],
                        $result['delivery_log'],
                        $rejection_id,
                    ],
                    'ssssi'
                );

                Security::logEvent('REJECTION_RESENT',
                    "Resent rejection notification (id={$rejection_id}) — overall={$result['overall']}"
                );

                $overall = $result['overall'];
                if ($overall === 'sent') {
                    header('Location: rejections_list.php?success=resent');
                } elseif ($overall === 'partial') {
                    header('Location: rejections_list.php?success=resend_partial');
                } else {
                    header('Location: rejections_list.php?error=' . urlencode('All channels failed to resend.'));
                }
                exit();
            }
        }

        /* ---------- DELETE ---------- */
        elseif ($action === 'delete' && $rejection_id > 0) {
            $db->execute("DELETE FROM pending_user_rejections WHERE id = ?", [$rejection_id], 'i');
            Security::logEvent('REJECTION_DELETED', "Deleted rejection record id={$rejection_id}");
            header('Location: rejections_list.php?success=deleted');
            exit();
        }
    }
}

/* ============================================================
   FILTERS + PAGINATION
   ============================================================ */
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
$allowed_limits = [10, 20, 30, 50, 100];
if (!in_array($limit, $allowed_limits, true)) $limit = 20;

$type = isset($_GET['type']) ? Security::sanitize($_GET['type']) : 'all';
if (!in_array($type, ['all', 'user', 'photo'], true)) $type = 'all';

$search         = isset($_GET['search'])         ? Security::sanitize($_GET['search'])         : '';
$filter_reason  = isset($_GET['filter_reason'])  ? Security::sanitize($_GET['filter_reason'])  : '';
$filter_channel = isset($_GET['filter_channel']) ? Security::sanitize($_GET['filter_channel']) : '';
$date_from      = isset($_GET['date_from'])      ? Security::sanitize($_GET['date_from'])      : '';
$date_to        = isset($_GET['date_to'])        ? Security::sanitize($_GET['date_to'])        : '';

$where_conditions = ['1=1'];
$params = [];
$types  = '';

if ($type === 'user' || $type === 'photo') {
    $where_conditions[] = "rejection_type = ?";
    $params[] = $type;
    $types .= 's';
}

if ($search !== '') {
    $where_conditions[] = "(full_name LIKE ? OR username LIKE ? OR email LIKE ? OR member_code LIKE ? OR custom_reason LIKE ?)";
    $like = "%$search%";
    for ($i = 0; $i < 5; $i++) { $params[] = $like; $types .= 's'; }
}

if ($filter_reason !== '') {
    $valid = RejectionReasons::isValid($filter_reason) || PhotoRejectionReasons::isValid($filter_reason);
    if ($valid) {
        $where_conditions[] = "reason_code = ?";
        $params[] = $filter_reason;
        $types .= 's';
    }
}

if ($filter_channel === 'email_failed') {
    $where_conditions[] = "email_status = 'failed'";
} elseif ($filter_channel === 'sms_failed') {
    $where_conditions[] = "sms_status = 'failed'";
} elseif ($filter_channel === 'notification_failed') {
    $where_conditions[] = "notification_status = 'failed'";
} elseif ($filter_channel === 'all_sent') {
    $where_conditions[] = "notification_status = 'sent' AND email_status = 'sent' AND sms_status = 'sent'";
}

if ($date_from !== '') {
    $where_conditions[] = "DATE(rejected_at) >= ?";
    $params[] = $date_from;
    $types .= 's';
}
if ($date_to !== '') {
    $where_conditions[] = "DATE(rejected_at) <= ?";
    $params[] = $date_to;
    $types .= 's';
}

$where_clause = 'WHERE ' . implode(' AND ', $where_conditions);

$total_records = (int)($db->getSingle("SELECT COUNT(*) as total FROM pending_user_rejections $where_clause", $params, $types)['total'] ?? 0);
$total_pages = max(1, (int)ceil($total_records / $limit));
if ($page > $total_pages) $page = $total_pages;
$offset = ($page - 1) * $limit;

$sql = "SELECT * FROM pending_user_rejections $where_clause ORDER BY rejected_at DESC LIMIT ? OFFSET ?";
$query_params = array_merge($params, [$limit, $offset]);
$rejections = $db->getAll($sql, $query_params, $types . 'ii');

$csrf_token = Security::generateCSRFToken();

/* ============================================================
   HELPERS
   ============================================================ */
function statusBadge(string $status): string {
    $map = [
        'sent'    => ['bg-success',  'check-circle',  'Sent'],
        'failed'  => ['bg-danger',   'times-circle',  'Failed'],
        'pending' => ['bg-warning text-dark', 'clock', 'Pending'],
        'skipped' => ['bg-secondary','minus-circle',  'Skipped'],
    ];
    $b = $map[$status] ?? ['bg-secondary', 'question-circle', ucfirst($status)];
    return '<span class="badge ' . $b[0] . ' status-badge">'
         . '<i class="fas fa-' . $b[1] . ' me-1"></i>' . $b[2] . '</span>';
}

function buildRejUrl($page, $limit, $search, $filter_reason, $filter_channel, $date_from, $date_to, $type) {
    $q = ['page' => $page, 'limit' => $limit];
    if ($type           !== 'all' && $type !== '') $q['type'] = $type;
    if ($search         !== '') $q['search']         = $search;
    if ($filter_reason  !== '') $q['filter_reason']  = $filter_reason;
    if ($filter_channel !== '') $q['filter_channel'] = $filter_channel;
    if ($date_from      !== '') $q['date_from']      = $date_from;
    if ($date_to        !== '') $q['date_to']        = $date_to;
    return '?' . http_build_query($q);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rejections - Harana Financial System</title>
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
    #wrapper { display: flex; width: 100%; height: 100vh; overflow: hidden; }

    /* ============================================================ */
    /* SIDEBAR                                                       */
    /* ============================================================ */
    #sidebar-wrapper {
        background: #1c1c1d; color: #fff;
        width: 250px; height: 100vh; overflow-y: auto;
        transition: width 0.3s ease;
        box-shadow: 4px 0 10px rgba(0,0,0,0.1);
        white-space: nowrap; flex-shrink: 0;
    }
    #sidebar-wrapper.collapsed { width: 70px; }
    #sidebar-wrapper.collapsed .list-group-item span { display: none; }
    #sidebar-wrapper.collapsed .list-group-item {
        text-align: center; padding: 15px 0; justify-content: center;
    }
    #sidebar-wrapper.collapsed .list-group-item i {
        margin-right: 0; width: 100%; text-align: center; font-size: 1.2rem;
    }
    #sidebar-wrapper .sidebar-heading {
        padding: 1rem 1rem .85rem;
        font-size: 1.1rem; font-weight: 700;
        border-bottom: 1px solid rgba(255,255,255,.1);
        background: #1c1c1d; color: white;
        display: flex; align-items: center; gap: 10px; line-height: 1.15;
    }
    #sidebar-wrapper .sidebar-heading .sidebar-logo {
        height: 34px; width: 34px; object-fit: contain; flex-shrink: 0;
    }
    #sidebar-wrapper .sidebar-heading .brand-text {
        display: flex; flex-direction: column; text-align: left; flex: 1; min-width: 0;
    }
    #sidebar-wrapper .sidebar-heading .brand-text .brand-acronym {
        font-size: 1.15rem; letter-spacing: .5px; font-weight: 800;
    }
    #sidebar-wrapper .sidebar-heading .brand-text .brand-full {
        font-size: .62rem; font-weight: 400; color: rgba(255,255,255,.7);
        letter-spacing: .2px; text-transform: uppercase; margin-top: 1px;
        white-space: normal; line-height: 1.15; max-width: 175px;
    }
    #sidebar-wrapper.collapsed .sidebar-heading .brand-text { display: none; }
    #sidebar-wrapper.collapsed .sidebar-heading .sidebar-logo { display: none; }
    #sidebar-wrapper.collapsed .sidebar-heading {
        justify-content: center; padding: 1rem 0; gap: 0;
    }
    #sidebar-wrapper .list-group-item {
        background: transparent; border: none;
        color: rgba(255,255,255,.9);
        padding: .8rem 1.2rem; font-weight: 500;
        transition: all .2s; font-size: .95rem;
        text-align: left; display: flex; align-items: center; gap: 10px;
    }
    #sidebar-wrapper .list-group-item i {
        width: 24px; text-align: center; font-size: 1rem; flex-shrink: 0;
    }
    #sidebar-wrapper .list-group-item:hover,
    #sidebar-wrapper .list-group-item.active {
        background: rgba(255,255,255,.15); color: #fff; border-left: 4px solid #fff;
    }
    #sidebar-wrapper.collapsed .list-group-item:hover,
    #sidebar-wrapper.collapsed .list-group-item.active {
        border-left: none; border-bottom: 2px solid #fff;
    }
    #sidebar-wrapper .list-group-item .badge-count {
        margin-left: auto; font-size: .65rem; padding: 3px 7px;
        line-height: 1; border-radius: 10px; flex-shrink: 0;
    }
    #sidebar-wrapper.collapsed .badge-count { display: none !important; }

    .menu-toggle {
        background: transparent; border: none; color: white;
        font-size: 1.5rem; cursor: pointer; padding: 0 10px;
        flex-shrink: 0; transition: all .2s;
    }
    .menu-toggle:hover { transform: scale(1.1); }

    /* ============================================================ */
    /* MAIN                                                          */
    /* ============================================================ */
    #page-content-wrapper {
        flex: 1; background: #f0f2f5; height: 100vh;
        overflow-y: auto; padding: 0; min-width: 0;
    }
    .navbar {
        background: #fff !important; box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        padding: 0.7rem 1.2rem;
        display: flex; align-items: center; justify-content: space-between;
        position: sticky; top: 0; z-index: 100;
    }
    .navbar-left { display: flex; align-items: center; gap: 8px; }
    .navbar-brand {
        font-size: 1.2rem; font-weight: 500;
        color: #1c1c1d !important; display: flex; align-items: center;
    }
    .navbar-brand i { color: #1c1c1d; font-size: 1.2rem; }
    .navbar-right { display: flex; align-items: center; gap: 20px; }
    .header-logo {
        height: 30px; width: auto; margin-right: 10px;
        vertical-align: middle; display: none;
    }
    #sidebar-wrapper.collapsed ~ #page-content-wrapper .header-logo { display: inline-block; }

    /* ============================================================ */
    /* TOP ROW: formal tabs (right)                                  */
    /* ============================================================ */
    .top-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 15px;
        margin-bottom: 16px;
        flex-wrap: wrap;
    }

    .page-tabs {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        margin-left: auto;
    }
    .page-tabs .tab {
        font-size: .85rem;
        font-weight: 600;
        color: #8a94a0;
        text-decoration: none;
        padding: 8px 4px 10px;
        margin: 0 14px;
        border-bottom: 2px solid transparent;
        transition: all .15s;
    }
    .page-tabs .tab:first-child { margin-left: 0; }
    .page-tabs .tab:last-child  { margin-right: 0; }
    .page-tabs .tab:hover { color: #1c1c1d; }
    .page-tabs .tab.active { color: #1c1c1d; border-bottom-color: #1c1c1d; }
    .page-tabs .tab .tab-count {
        display: inline-block;
        font-size: .68rem;
        font-weight: 700;
        padding: 1px 7px;
        margin-left: 6px;
        border-radius: 10px;
        background: #e9ecef;
        color: #6c757d;
        vertical-align: middle;
    }
    .page-tabs .tab.active .tab-count { background: #1c1c1d; color: #fff; }
    .page-tabs .tab-count.danger { background: #fdecee; color: #842029; }
    .page-tabs .tab.active .tab-count.danger { background: #842029; color: #fff; }

    /* ============================================================ */
    /* BACK BUTTON (dynamic)                                         */
    /* ============================================================ */
    .back-to-pending {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        height: 38px;
        padding: 0 16px;
        background: #fff;
        border: 1px solid #dee2e6;
        color: #1c1c1d;
        border-radius: 8px;
        font-size: 0.85rem;
        font-weight: 600;
        text-decoration: none;
        transition: all 0.15s;
        white-space: nowrap;
    }
    .back-to-pending:hover {
        background: #1c1c1d;
        color: #fff;
        border-color: #1c1c1d;
        text-decoration: none;
        transform: translateY(-1px);
    }
    .back-to-pending i { font-size: 0.8rem; }

    /* ============================================================ */
    /* SEARCH + FILTER                                               */
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
    .action-buttons-group { display: flex; gap: 10px; align-items: center; }
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
        background: #fff;
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

    .btn-primary { background: #1c1c1d; border-color: #1c1c1d; }
    .btn-primary:hover, .btn-primary:focus, .btn-primary:active {
        background: #2c2c2d !important; border-color: #2c2c2d !important;
    }

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
    .filter-actions { display: flex; gap: 8px; }
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

    /* ============================================================ */
    /* TABLE                                                         */
    /* ============================================================ */
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

    .status-badge {
        font-size: .68rem;
        padding: 3px 8px;
        border-radius: 10px;
        font-weight: 600;
        letter-spacing: .2px;
    }

    .reason-chip {
        display: inline-block;
        padding: 3px 10px;
        border-radius: 12px;
        font-size: .72rem;
        font-weight: 600;
        background: #fdecee;
        color: #842029;
        border: 1px solid #f5c2c7;
        max-width: 220px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        vertical-align: middle;
    }
    .reason-chip.other {
        background: #fff8e1;
        color: #856404;
        border-color: #ffeeba;
    }

    .type-badge {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        font-size: .68rem;
        font-weight: 700;
        letter-spacing: .3px;
        text-transform: uppercase;
        padding: 3px 9px;
        border-radius: 6px;
        white-space: nowrap;
    }
    .type-badge.user  { background: #e7f1ff; color: #0a4a9c; border: 1px solid #b8d4f5; }
    .type-badge.photo { background: #f3e7ff; color: #6f2da8; border: 1px solid #d9bef5; }

    .delivery-cell {
        display: flex;
        gap: 5px;
        flex-wrap: wrap;
    }

    .action-btn {
        font-size: .72rem;
        padding: 3px 9px;
        border-radius: 6px;
        border: 1px solid #dee2e6;
        background: #fff;
        color: #495057;
        transition: all .15s;
        text-decoration: none;
    }
    .action-btn:hover {
        background: #f8f9fa;
        color: #1c1c1d;
        border-color: #adb5bd;
        text-decoration: none;
    }
    .action-btn.danger:hover {
        background: #fdecee;
        color: #842029;
        border-color: #f5c2c7;
    }
    .action-btn.success:hover {
        background: #d1e7dd;
        color: #0f5132;
        border-color: #badbcc;
    }

    /* ============================================================ */
    /* PAGINATION                                                    */
    /* ============================================================ */
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

    /* ============================================================ */
    /* DETAIL MODAL                                                  */
    /* ============================================================ */
    .rej-detail-modal .modal-header {
        background: #1c1c1d;
        color: #fff;
    }
    .rej-detail-modal .section-title {
        background: #1c1c1d;
        color: #fff;
        padding: 6px 12px;
        border-radius: 6px;
        margin: 15px 0 10px;
        font-size: 13px;
        font-weight: 600;
    }
    .rej-detail-modal .info-table {
        width: 100%;
        margin-bottom: 15px;
    }
    .rej-detail-modal .info-table td {
        padding: 8px;
        border: 1px solid #dee2e6;
        vertical-align: top;
        font-size: .85rem;
    }
    .rej-detail-modal .info-table .label {
        font-weight: 600;
        background-color: #f8f9fa;
        width: 180px;
    }
    .rej-detail-modal .reason-box {
        background: #fdecee;
        border-left: 4px solid #dc3545;
        border-radius: 6px;
        padding: 14px 16px;
        margin-bottom: 15px;
    }
    .rej-detail-modal .reason-box .reason-label {
        font-size: .72rem;
        text-transform: uppercase;
        letter-spacing: .5px;
        color: #842029;
        font-weight: 700;
        margin-bottom: 4px;
    }
    .rej-detail-modal .reason-box .reason-main {
        font-size: 1rem;
        font-weight: 600;
        color: #842029;
        margin-bottom: 6px;
    }
    .rej-detail-modal .reason-box .reason-message {
        font-size: .88rem;
        color: #4a4a4a;
        white-space: pre-wrap;
    }
    .rej-detail-modal .reason-box .custom-note {
        margin-top: 10px;
        padding: 10px 12px;
        background: #fff8e1;
        border-left: 3px solid #ffc107;
        border-radius: 4px;
        font-size: .84rem;
        color: #4a4a4a;
    }
    .rej-detail-modal .delivery-log {
        background: #1c1c1d;
        color: #e9ecef;
        padding: 12px 16px;
        border-radius: 6px;
        font-family: monospace;
        font-size: .74rem;
        white-space: pre-wrap;
        word-break: break-all;
        max-height: 240px;
        overflow-y: auto;
    }

    /* ============================================================ */
    /* RESPONSIVE                                                    */
    /* ============================================================ */
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
        .header-logo { display: inline-block !important; }
        .top-row { flex-direction: column; align-items: stretch; }
        .page-tabs { margin-left: 0; justify-content: flex-end; }
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
            <a href="settings.php" class="list-group-item"><i class="fas fa-cog"></i><span>Settings</span></a>
            <a href="../logout.php" class="list-group-item" onclick="return confirm('Are you sure?');"><i class="fas fa-sign-out-alt"></i><span>Logout</span></a>
        </div>
    </div>

    <!-- Page content -->
    <div id="page-content-wrapper">
        <nav class="navbar navbar-light bg-light">
            <div class="navbar-left">
                <img src="../assets/images/harana-logo.png" alt="Harana" class="header-logo" id="headerLogo" onerror="this.style.display='none';">
                <span class="navbar-brand"><i class="fas fa-user-times me-2"></i>Rejection History</span>
            </div>
            <div class="navbar-right">
                <span class="text-muted small"><i class="fas fa-calendar-alt me-1"></i><?php echo date('F j, Y'); ?></span>
                <span class="small"><i class="fas fa-user-circle me-2"></i><?php echo htmlspecialchars($current_user['full_name'] ?? $current_user['username']); ?></span>
            </div>
        </nav>

        <div class="container-fluid p-3">

            <!-- Toasts -->
            <div class="toast-container position-fixed top-0 end-0 p-3" style="z-index:999999;top:70px!important;">
                <div id="successToast" class="toast align-items-center text-white bg-success border-0" role="alert">
                    <div class="d-flex">
                        <div class="toast-body"><i class="fas fa-check-circle me-2"></i><span id="successMessage"></span></div>
                        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
                    </div>
                </div>
                <div id="errorToast" class="toast align-items-center text-white bg-danger border-0" role="alert">
                    <div class="d-flex">
                        <div class="toast-body"><i class="fas fa-exclamation-circle me-2"></i><span id="errorMessage"></span></div>
                        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
                    </div>
                </div>
            </div>

            <!-- ============================================================ -->
            <!-- TOP ROW: formal tabs (right)                                  -->
            <!-- ============================================================ -->
            <div class="top-row">
                <div></div>
                <div class="page-tabs">
                    <a href="?type=user" class="tab <?php echo $type === 'user' ? 'active' : ''; ?>">
                        Rejected User Registrations
                        <?php if ($user_rejection_count > 0): ?>
                            <span class="tab-count danger"><?php echo $user_rejection_count; ?></span>
                        <?php endif; ?>
                    </a>
                    <a href="?type=photo" class="tab <?php echo $type === 'photo' ? 'active' : ''; ?>">
                        Rejected Photo Requests
                        <?php if ($photo_rejection_count > 0): ?>
                            <span class="tab-count danger"><?php echo $photo_rejection_count; ?></span>
                        <?php endif; ?>
                    </a>
                    <a href="?type=all" class="tab <?php echo $type === 'all' ? 'active' : ''; ?>">
                        All Rejections
                        <?php if ($total_rejection_count > 0): ?>
                            <span class="tab-count"><?php echo $total_rejection_count; ?></span>
                        <?php endif; ?>
                    </a>
                </div>
            </div>

            <!-- Toolbar: search (left) + dynamic Back button (right) -->
            <div class="action-toolbar">
                <div class="search-group">
                    <form method="GET" class="d-flex w-100 gap-2">
                        <input type="hidden" name="type" value="<?php echo htmlspecialchars($type); ?>">
                        <input type="hidden" name="limit" value="<?php echo $limit; ?>">
                        <input type="text" name="search" class="search-input"
                               placeholder="Search by name, username, email, member code..."
                               value="<?php echo htmlspecialchars($search); ?>">
                        <button type="submit" class="btn-search"><i class="fas fa-search"></i> Search</button>
                    </form>
                </div>
                <div class="action-buttons-group">
                    <?php if ($type === 'photo'): ?>
                        <a href="pending_photos.php" class="back-to-pending">
                            <i class="fas fa-arrow-left"></i>
                            Back
                        </a>
                    <?php elseif ($type === 'user'): ?>
                        <a href="pending_users.php" class="back-to-pending">
                            <i class="fas fa-arrow-left"></i>
                            Back
                        </a>
                    <?php else: ?>
                        <a href="pending_users.php" class="back-to-pending">
                            <i class="fas fa-arrow-left"></i>
                            Back
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Filter bar -->
            <div class="filter-bar">
                <form method="GET" class="filter-row">
                    <input type="hidden" name="type"   value="<?php echo htmlspecialchars($type); ?>">
                    <input type="hidden" name="search" value="<?php echo htmlspecialchars($search); ?>">

                    <div class="filter-item">
                        <label>Reason</label>
                        <select name="filter_reason" class="form-select">
                            <option value="">All Reasons</option>
                            <?php if ($type === 'photo'): ?>
                                <?php foreach (PhotoRejectionReasons::grouped() as $g => $rows): ?>
                                    <optgroup label="<?php echo htmlspecialchars($g); ?>">
                                        <?php foreach ($rows as $code => $row): ?>
                                            <option value="<?php echo htmlspecialchars($code); ?>"
                                                <?php echo $filter_reason === $code ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($row['label']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                <?php endforeach; ?>
                            <?php elseif ($type === 'user'): ?>
                                <?php foreach (RejectionReasons::grouped() as $g => $rows): ?>
                                    <optgroup label="<?php echo htmlspecialchars($g); ?>">
                                        <?php foreach ($rows as $code => $row): ?>
                                            <option value="<?php echo htmlspecialchars($code); ?>"
                                                <?php echo $filter_reason === $code ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($row['label']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <optgroup label="User Registration Reasons">
                                    <?php foreach (RejectionReasons::grouped() as $g => $rows): ?>
                                        <?php foreach ($rows as $code => $row): ?>
                                            <option value="<?php echo htmlspecialchars($code); ?>"
                                                <?php echo $filter_reason === $code ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($row['label']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    <?php endforeach; ?>
                                </optgroup>
                                <optgroup label="Photo Request Reasons">
                                    <?php foreach (PhotoRejectionReasons::grouped() as $g => $rows): ?>
                                        <?php foreach ($rows as $code => $row): ?>
                                            <option value="<?php echo htmlspecialchars($code); ?>"
                                                <?php echo $filter_reason === $code ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($row['label']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endif; ?>
                        </select>
                    </div>

                    <div class="filter-item">
                        <label>Delivery Issue</label>
                        <select name="filter_channel" class="form-select">
                            <option value="">Any</option>
                            <option value="all_sent"            <?php echo $filter_channel === 'all_sent'            ? 'selected' : ''; ?>>All channels OK</option>
                            <option value="email_failed"        <?php echo $filter_channel === 'email_failed'        ? 'selected' : ''; ?>>Email failed</option>
                            <option value="sms_failed"          <?php echo $filter_channel === 'sms_failed'          ? 'selected' : ''; ?>>SMS failed</option>
                            <option value="notification_failed" <?php echo $filter_channel === 'notification_failed' ? 'selected' : ''; ?>>In-app failed</option>
                        </select>
                    </div>

                    <div class="filter-item">
                        <label>From Date</label>
                        <input type="date" name="date_from" class="form-control" value="<?php echo htmlspecialchars($date_from); ?>">
                    </div>

                    <div class="filter-item">
                        <label>To Date</label>
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
                        <a href="rejections_list.php?type=<?php echo urlencode($type); ?>" class="btn btn-secondary"><i class="fas fa-undo me-1"></i> Reset</a>
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
                                    <th style="width:120px;">Type</th>
                                    <th style="width:130px;">Rejected At</th>
                                    <th>Applicant</th>
                                    <th>Contact</th>
                                    <th style="width:220px;">Reason</th>
                                    <th style="width:180px;">Delivery</th>
                                    <th style="width:140px;">By</th>
                                    <th style="width:120px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (empty($rejections)): ?>
                                <tr><td colspan="8" class="text-center py-5">
                                    <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                                    <p class="text-muted mb-0">
                                        <?php echo ($search || $filter_reason || $filter_channel || $date_from || $date_to)
                                            ? 'No rejections match your filters.'
                                            : 'No rejections recorded yet.'; ?>
                                    </p>
                                </td></tr>
                            <?php else: foreach ($rejections as $r):
                                $isPhoto = ($r['rejection_type'] ?? 'user') === 'photo';
                            ?>
                                <tr>
                                    <td>
                                        <?php if ($isPhoto): ?>
                                            <span class="type-badge photo">
                                                <i class="fas fa-camera"></i> Photo
                                            </span>
                                        <?php else: ?>
                                            <span class="type-badge user">
                                                <i class="fas fa-user"></i> User
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <small><?php echo date('M d, Y', strtotime($r['rejected_at'])); ?></small><br>
                                        <small class="text-muted"><?php echo date('H:i', strtotime($r['rejected_at'])); ?></small>
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($r['full_name']); ?></strong>
                                        <?php if (!empty($r['member_code'])): ?>
                                            <br><span class="badge bg-secondary" style="font-size:.62rem;"><?php echo htmlspecialchars($r['member_code']); ?></span>
                                        <?php endif; ?>
                                        <br><small class="text-muted"><i class="fas fa-at me-1"></i><?php echo htmlspecialchars($r['username']); ?></small>
                                    </td>
                                    <td>
                                        <small><i class="fas fa-envelope text-muted me-1"></i><?php echo htmlspecialchars($r['email']); ?></small>
                                        <?php if (!empty($r['contact_number'])): ?>
                                            <br><small><i class="fas fa-phone text-muted me-1"></i><?php echo htmlspecialchars($r['contact_number']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="reason-chip <?php echo $r['reason_code'] === 'other' ? 'other' : ''; ?>"
                                              title="<?php echo htmlspecialchars($r['reason_message'] . (($r['custom_reason'] ?? '') !== '' ? "\n\nCustom: " . $r['custom_reason'] : '')); ?>">
                                            <?php echo htmlspecialchars($r['reason_label']); ?>
                                        </span>
                                        <?php if (!empty($r['custom_reason'])): ?>
                                            <br><small class="text-muted" style="font-size:.68rem;">
                                                <i class="fas fa-pencil-alt me-1"></i><?php
                                                    $cr = $r['custom_reason'];
                                                    echo htmlspecialchars(mb_strlen($cr) > 40 ? mb_substr($cr, 0, 40) . '…' : $cr);
                                                ?>
                                            </small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="delivery-cell">
                                            <?php echo statusBadge($r['notification_status']); ?>
                                            <?php echo statusBadge($r['email_status']); ?>
                                            <?php echo statusBadge($r['sms_status']); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <small><i class="fas fa-user-shield text-muted me-1"></i><?php echo htmlspecialchars($r['rejected_by_name']); ?></small>
                                    </td>
                                    <td>
                                        <div class="d-flex gap-1 flex-wrap">
                                            <button type="button" class="action-btn"
                                                    onclick='viewRejection(<?php echo json_encode([
                                                        "id"                  => (int)$r["id"],
                                                        "rejection_type"      => $r["rejection_type"] ?? "user",
                                                        "full_name"           => $r["full_name"],
                                                        "username"            => $r["username"],
                                                        "email"               => $r["email"],
                                                        "contact_number"      => $r["contact_number"],
                                                        "member_code"         => $r["member_code"],
                                                        "reason_code"         => $r["reason_code"],
                                                        "reason_label"        => $r["reason_label"],
                                                        "reason_message"      => $r["reason_message"],
                                                        "custom_reason"       => $r["custom_reason"],
                                                        "rejected_at"         => $r["rejected_at"],
                                                        "rejected_by_name"    => $r["rejected_by_name"],
                                                        "notification_status" => $r["notification_status"],
                                                        "email_status"        => $r["email_status"],
                                                        "sms_status"          => $r["sms_status"],
                                                        "notification_sent_at"=> $r["notification_sent_at"],
                                                        "email_sent_at"       => $r["email_sent_at"],
                                                        "sms_sent_at"         => $r["sms_sent_at"],
                                                        "delivery_log"        => $r["delivery_log"],
                                                    ], JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'
                                                    title="View details">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <form method="POST" style="display:inline;"
                                                  onsubmit="return confirm('Resend the rejection notification?\n\nThis will fire in-app + email + SMS again.');">
                                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                                <input type="hidden" name="action" value="resend">
                                                <input type="hidden" name="rejection_id" value="<?php echo (int)$r['id']; ?>">
                                                <button type="submit" class="action-btn success" title="Resend notification">
                                                    <i class="fas fa-paper-plane"></i>
                                                </button>
                                            </form>
                                            <form method="POST" style="display:inline;"
                                                  onsubmit="return confirm('Delete this rejection record?\n\nThis does NOT unsend emails/SMS already sent. Only the admin record is removed.');">
                                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="rejection_id" value="<?php echo (int)$r['id']; ?>">
                                                <button type="submit" class="action-btn danger" title="Delete record">
                                                    <i class="fas fa-trash-alt"></i>
                                                </button>
                                            </form>
                                        </div>
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
                            Showing <?php echo (($page - 1) * $limit) + 1; ?> –
                            <?php echo min($page * $limit, $total_records); ?>
                            of <?php echo number_format($total_records); ?> records
                        </div>
                        <nav>
                            <ul class="pagination">
                                <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="<?php echo buildRejUrl(1, $limit, $search, $filter_reason, $filter_channel, $date_from, $date_to, $type); ?>">
                                        <i class="fas fa-angle-double-left"></i>
                                    </a>
                                </li>
                                <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="<?php echo buildRejUrl($page - 1, $limit, $search, $filter_reason, $filter_channel, $date_from, $date_to, $type); ?>">
                                        <i class="fas fa-chevron-left"></i>
                                    </a>
                                </li>
                                <?php
                                $start = max(1, $page - 2);
                                $end   = min($total_pages, $page + 2);
                                for ($i = $start; $i <= $end; $i++):
                                ?>
                                <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                    <a class="page-link" href="<?php echo buildRejUrl($i, $limit, $search, $filter_reason, $filter_channel, $date_from, $date_to, $type); ?>"><?php echo $i; ?></a>
                                </li>
                                <?php endfor; ?>
                                <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="<?php echo buildRejUrl($page + 1, $limit, $search, $filter_reason, $filter_channel, $date_from, $date_to, $type); ?>">
                                        <i class="fas fa-chevron-right"></i>
                                    </a>
                                </li>
                                <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="<?php echo buildRejUrl($total_pages, $limit, $search, $filter_reason, $filter_channel, $date_from, $date_to, $type); ?>">
                                        <i class="fas fa-angle-double-right"></i>
                                    </a>
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

<!-- Rejection Detail Modal -->
<div class="modal fade rej-detail-modal" id="rejDetailModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h5 class="modal-title"><i class="fas fa-user-times me-2"></i>Rejection Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="rejDetailBody">
                <div class="text-center p-4">
                    <div class="spinner-border text-dark"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
/* ---------- Sidebar ---------- */
const sidebar = document.getElementById('sidebar-wrapper');
document.getElementById('menuToggle')?.addEventListener('click', () => {
    sidebar.classList.toggle('collapsed');
    localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
});
if (localStorage.getItem('sidebarCollapsed') === 'true') sidebar.classList.add('collapsed');

/* ---------- Toasts ---------- */
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

/* ---------- Status badge helper ---------- */
function jsStatusBadge(status) {
    const map = {
        'sent':    { cls: 'bg-success',           icon: 'check-circle',  label: 'Sent' },
        'failed':  { cls: 'bg-danger',            icon: 'times-circle',  label: 'Failed' },
        'pending': { cls: 'bg-warning text-dark', icon: 'clock',         label: 'Pending' },
        'skipped': { cls: 'bg-secondary',         icon: 'minus-circle',  label: 'Skipped' },
    };
    const b = map[status] || { cls: 'bg-secondary', icon: 'question-circle', label: status };
    return `<span class="badge ${b.cls} status-badge"><i class="fas fa-${b.icon} me-1"></i>${b.label}</span>`;
}

function escapeHtml(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({
        '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;'
    }[c]));
}

function fmtDateTime(d) {
    if (!d) return '—';
    const dt = new Date(d.replace(' ', 'T'));
    if (isNaN(dt.getTime())) return d;
    return dt.toLocaleString('en-US', {
        year: 'numeric', month: 'short', day: 'numeric',
        hour: '2-digit', minute: '2-digit'
    });
}

/* ---------- Detail modal ---------- */
function viewRejection(r) {
    const modal = new bootstrap.Modal(document.getElementById('rejDetailModal'));
    const body  = document.getElementById('rejDetailBody');

    const isOther = r.reason_code === 'other';
    const isPhoto = r.rejection_type === 'photo';

    let customBlock = '';
    if (r.custom_reason && r.custom_reason.trim() !== '') {
        customBlock = `
            <div class="custom-note">
                <strong style="color:#856404;font-size:.72rem;text-transform:uppercase;letter-spacing:.4px;">Custom note from admin</strong>
                <div style="margin-top:4px;">${escapeHtml(r.custom_reason)}</div>
            </div>`;
    }

    body.innerHTML = `
        <div style="margin-bottom:14px;">
            ${isPhoto
                ? '<span class="type-badge photo"><i class="fas fa-camera"></i> Photo Request Rejection</span>'
                : '<span class="type-badge user"><i class="fas fa-user"></i> User Registration Rejection</span>'}
        </div>

        <div class="reason-box">
            <div class="reason-label">Reason for rejection</div>
            <div class="reason-main">${escapeHtml(r.reason_label)}</div>
            <div class="reason-message">${escapeHtml(r.reason_message || '')}</div>
            ${customBlock}
        </div>

        <div class="section-title">Applicant Information</div>
        <table class="info-table">
            <tr>
                <td class="label">Full Name:</td><td>${escapeHtml(r.full_name)}</td>
                <td class="label">Member Code:</td><td>${escapeHtml(r.member_code || '—')}</td>
            </tr>
            <tr>
                <td class="label">Username:</td><td>${escapeHtml(r.username)}</td>
                <td class="label">Contact:</td><td>${escapeHtml(r.contact_number || '—')}</td>
            </tr>
            <tr>
                <td class="label">Email:</td><td colspan="3">${escapeHtml(r.email)}</td>
            </tr>
        </table>

        <div class="section-title">Rejection Meta</div>
        <table class="info-table">
            <tr>
                <td class="label">Rejected By:</td><td>${escapeHtml(r.rejected_by_name)}</td>
                <td class="label">Rejected At:</td><td>${escapeHtml(fmtDateTime(r.rejected_at))}</td>
            </tr>
            <tr>
                <td class="label">Reason Code:</td><td><code>${escapeHtml(r.reason_code)}</code></td>
                <td class="label">Type:</td><td>${isOther ? 'Custom (Others)' : 'Predefined'}</td>
            </tr>
        </table>

        <div class="section-title">Delivery Status</div>
        <table class="info-table">
            <tr>
                <td class="label">In-app Notification:</td>
                <td>${jsStatusBadge(r.notification_status)} <small class="text-muted ms-1">${escapeHtml(fmtDateTime(r.notification_sent_at))}</small></td>
                <td class="label">Email:</td>
                <td>${jsStatusBadge(r.email_status)} <small class="text-muted ms-1">${escapeHtml(fmtDateTime(r.email_sent_at))}</small></td>
            </tr>
            <tr>
                <td class="label">SMS:</td>
                <td colspan="3">${jsStatusBadge(r.sms_status)} <small class="text-muted ms-1">${escapeHtml(fmtDateTime(r.sms_sent_at))}</small></td>
            </tr>
        </table>

        <div class="section-title">Delivery Log</div>
        <div class="delivery-log">${escapeHtml(r.delivery_log || '(no log recorded)')}</div>
    `;

    modal.show();
}
</script>
</body>
</html>