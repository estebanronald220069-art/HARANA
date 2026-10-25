<?php
// admin/pending_photos.php
require_once '../includes/config.php';
require_once '../includes/db_connection.php';
require_once '../includes/security.php';
require_once '../includes/auth.php';
require_once '../includes/user_functions.php';
require_once '../includes/rejection_reasons_photos.php';
require_once '../includes/notifier.php';

$auth->requireLogin();
$auth->requireRole('admin');
$current_user = $auth->getCurrentUser();

$db = Database::getInstance();

/* ============================================================
   COUNTS
   ============================================================ */
$user_pending_count  = (int)($db->getSingle("SELECT COUNT(*) as cnt FROM pending_users WHERE status = 'pending'")['cnt'] ?? 0);
$photo_pending_count = (int)($db->getSingle("SELECT COUNT(*) as cnt FROM member_photo_requests WHERE status = 'pending'")['cnt'] ?? 0);
$total_pending       = $user_pending_count + $photo_pending_count;

$rejection_count = 0;
$photo_rejection_count = 0;
try {
    $rejection_count       = (int)($db->getSingle("SELECT COUNT(*) as cnt FROM pending_user_rejections WHERE rejection_type = 'user'")['cnt'] ?? 0);
    $photo_rejection_count = (int)($db->getSingle("SELECT COUNT(*) as cnt FROM pending_user_rejections WHERE rejection_type = 'photo'")['cnt'] ?? 0);
} catch (Throwable $e) {
    try {
        $rejection_count = (int)($db->getSingle("SELECT COUNT(*) as cnt FROM pending_user_rejections")['cnt'] ?? 0);
    } catch (Throwable $e2) {}
}

$success_toast = '';
$error_toast   = '';

/* ============================================================
   PAGINATION + SEARCH
   ============================================================ */
$page  = max(1, (int)($_GET['page'] ?? 1));
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
$allowed_limits = [10, 20, 30, 50, 100];
if (!in_array($limit, $allowed_limits, true)) $limit = 20;

$search = isset($_GET['search']) ? Security::sanitize($_GET['search']) : '';

$where = "status = 'pending'";
$params = [];
$types = '';

if ($search !== '') {
    $where .= " AND (full_name LIKE ? OR username LIKE ? OR member_code LIKE ?)";
    $like = "%$search%";
    $params = [$like, $like, $like];
    $types = 'sss';
}

$total_records = (int)($db->getSingle("SELECT COUNT(*) as total FROM member_photo_requests WHERE $where", $params, $types)['total'] ?? 0);
$total_pages = max(1, (int)ceil($total_records / $limit));
if ($page > $total_pages) $page = $total_pages;
$offset = ($page - 1) * $limit;

$sql = "SELECT * FROM member_photo_requests WHERE $where ORDER BY requested_at DESC LIMIT ? OFFSET ?";
$qp  = array_merge($params, [$limit, $offset]);
$requests = $db->getAll($sql, $qp, $types . 'ii');

$csrf_token = Security::generateCSRFToken();

/* ============================================================
   POST — APPROVE / REJECT / DELETE
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error_toast = 'Invalid security token.';
    } else {
        $action = $_POST['action'] ?? '';
        $request_id = (int)($_POST['request_id'] ?? 0);

        /* ---------- APPROVE ---------- */
        if ($action === 'approve') {
            $req = $db->getSingle("SELECT * FROM member_photo_requests WHERE id = ?", [$request_id], 'i');
            if (!$req) {
                $error_toast = 'Request not found.';
            } else {
                $db->getConnection()->begin_transaction();
                try {
                    $new_photo_path = null;
                    if (!empty($req['requested_photo'])) {
                        $old = '../' . $req['requested_photo'];
                        if (file_exists($old)) {
                            $ext = strtolower(pathinfo($old, PATHINFO_EXTENSION));
                            $new = 'member_' . ($req['member_code'] ?? 'unknown') . '_' . time() . '.' . $ext;
                            $dest = '../uploads/members/' . $new;
                            if (!is_dir('../uploads/members/')) @mkdir('../uploads/members/', 0755, true);
                            if (rename($old, $dest)) $new_photo_path = 'uploads/members/' . $new;
                        }
                    }

                    if ($new_photo_path) {
                        $db->execute(
                            "UPDATE members SET profile_photo = ? WHERE member_code = ?",
                            [$new_photo_path, $req['member_code']],
                            'ss'
                        );
                    }

                    $db->execute("UPDATE member_photo_requests SET status = 'approved', reviewed_at = NOW(), reviewed_by = ? WHERE id = ?",
                        [$current_user['user_id'], $request_id], 'ii');

                    $db->getConnection()->commit();
                    Security::logEvent('PHOTO_APPROVED', "Approved photo request ID {$request_id} for {$req['username']}");

                    try {
                        $u = $db->getSingle("SELECT user_id FROM users WHERE username = ?", [$req['username']], 's');
                        if ($u && function_exists('createNotification')) {
                            createNotification($db, $u['user_id'], "Profile Photo Updated",
                                "Your new profile photo has been approved and applied to your account.",
                                'account', 'profile.php');
                        }
                    } catch (Throwable $e) { /* silent */ }

                    header('Location: pending_photos.php?success=approved&page=' . $page . '&limit=' . $limit);
                    exit();
                } catch (Exception $e) {
                    $db->getConnection()->rollback();
                    $error_toast = 'Failed to approve: ' . $e->getMessage();
                }
            }
        }

        /* ---------- REJECT (with reason) ---------- */
        elseif ($action === 'reject') {
            $req = $db->getSingle("SELECT * FROM member_photo_requests WHERE id = ?", [$request_id], 'i');
            if (!$req) {
                $error_toast = 'Request not found.';
            } else {
                $reason_code   = (string)($_POST['reason_code']   ?? '');
                $custom_reason = (string)($_POST['custom_reason'] ?? '');
                $check = PhotoRejectionReasons::validate($reason_code, $custom_reason);

                if (!$check['ok']) {
                    $error_toast = $check['error'];
                } else {
                    $full_name = $req['full_name'] ?? 'Member';

                    $insert_ok = $db->execute(
                        "INSERT INTO pending_user_rejections
                            (pending_user_id, rejection_type, member_code, full_name, username, email, contact_number,
                             reason_code, reason_label, reason_message, custom_reason,
                             rejected_by, rejected_by_name, rejected_at,
                             notification_status, email_status, sms_status, delivery_log)
                         VALUES (?, 'photo', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(),
                                 'pending','pending','pending', NULL)",
                        [
                            $request_id,
                            $req['member_code']    ?? null,
                            $full_name,
                            $req['username']       ?? '',
                            $req['email']          ?? '',
                            $req['contact_number'] ?? null,
                            $check['reason_code'],
                            $check['reason_label'],
                            $check['reason_message'],
                            $check['custom_reason'] !== '' ? $check['custom_reason'] : null,
                            $current_user['user_id'],
                            $current_user['full_name'] ?? $current_user['username'],
                        ],
                        'isssssssssiss'
                    );

                    if (!$insert_ok) {
                        $error_toast = 'Failed to save rejection: ' . $db->getConnection()->error;
                    } else {
                        $rejection_id = (int)$db->getConnection()->insert_id;

                        // Build a pending-like structure for Notifier
                        $pendingCtx = [
                            'first_name'     => explode(' ', $full_name)[0] ?? '',
                            'last_name'      => '',
                            'email'          => $req['email']          ?? '',
                            'contact_number' => $req['contact_number'] ?? '',
                            'username'       => $req['username']       ?? '',
                            'member_code'    => $req['member_code']    ?? '',
                        ];
                        $parts = preg_split('/\s+/', trim($full_name));
                        if (count($parts) >= 2) {
                            $pendingCtx['first_name'] = array_shift($parts);
                            $pendingCtx['last_name']  = implode(' ', $parts);
                        }

                        try {
                            $notify_result = Notifier::sendRejection($pendingCtx, $check);
                        } catch (Throwable $e) {
                            $notify_result = [
                                'overall'      => 'failed',
                                'notification' => ['status' => 'failed', 'error' => $e->getMessage()],
                                'email'        => ['status' => 'failed', 'error' => $e->getMessage()],
                                'sms'          => ['status' => 'failed', 'error' => $e->getMessage()],
                                'delivery_log' => 'EXCEPTION: ' . $e->getMessage(),
                            ];
                        }

                        $db->execute(
                            "UPDATE pending_user_rejections
                             SET notification_status = ?,
                                 email_status        = ?,
                                 sms_status          = ?,
                                 notification_sent_at = " . ($notify_result['notification']['status'] === 'sent' ? 'NOW()' : 'NULL') . ",
                                 email_sent_at        = " . ($notify_result['email']['status']        === 'sent' ? 'NOW()' : 'NULL') . ",
                                 sms_sent_at          = " . ($notify_result['sms']['status']          === 'sent' ? 'NOW()' : 'NULL') . ",
                                 delivery_log         = ?
                             WHERE id = ?",
                            [
                                $notify_result['notification']['status'],
                                $notify_result['email']['status'],
                                $notify_result['sms']['status'],
                                $notify_result['delivery_log'],
                                $rejection_id,
                            ],
                            'ssssi'
                        );

                        if (!empty($req['requested_photo'])) {
                            $p = '../' . $req['requested_photo'];
                            if (file_exists($p)) @unlink($p);
                        }

                        $db->execute(
                            "UPDATE member_photo_requests
                             SET status = 'rejected', reviewed_at = NOW(), reviewed_by = ?
                             WHERE id = ?",
                            [$current_user['user_id'], $request_id],
                            'ii'
                        );

                        Security::logEvent('PHOTO_REJECTED',
                            "Rejected photo request {$request_id} for {$req['username']} — Reason: {$check['reason_label']} | Delivery: {$notify_result['overall']}"
                        );

                        header('Location: pending_photos.php?success=rejected&page=' . $page . '&limit=' . $limit);
                        exit();
                    }
                }
            }
        }

        /* ---------- DELETE ---------- */
        elseif ($action === 'delete') {
            $req = $db->getSingle("SELECT * FROM member_photo_requests WHERE id = ?", [$request_id], 'i');
            if ($req && !empty($req['requested_photo'])) {
                $p = '../' . $req['requested_photo'];
                if (file_exists($p)) @unlink($p);
            }
            $db->execute("DELETE FROM member_photo_requests WHERE id = ?", [$request_id], 'i');
            Security::logEvent('PHOTO_REQUEST_DELETED', "Deleted photo request ID: $request_id");
            header('Location: pending_photos.php?success=deleted&page=' . $page . '&limit=' . $limit);
            exit();
        }
    }
}

/* ============================================================
   FLASH
   ============================================================ */
if (isset($_GET['success'])) {
    $map = [
        'approved' => 'Photo approved and applied to the member\'s profile.',
        'rejected' => 'Photo request rejected and member notified.',
        'deleted'  => 'Photo request deleted.',
    ];
    $success_toast = $map[$_GET['success']] ?? '';
}

function buildPhotoUrl($page, $limit, $search) {
    $q = ['page' => $page, 'limit' => $limit];
    if ($search !== '') $q['search'] = $search;
    return '?' . http_build_query($q);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pending Photo Requests - <?php echo APP_NAME; ?></title>
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
        margin: 0; padding: 0;
    }
    #wrapper { display: flex; width: 100%; height: 100vh; overflow: hidden; }

    /* ============================================================ */
    /* SIDEBAR                                                       */
    /* ============================================================ */
    #sidebar-wrapper {
        background: #1c1c1d; color: #fff;
        width: 250px; height: 100vh;
        overflow-y: auto;
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
    /* TOP ROW: sub-tabs (right) + rejections dropdown               */
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
        position: relative;
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

    /* Rejections dropdown (matches dashboard) */
    .view-dropdown-btn {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        background: #1c1c1d;
        color: white;
        border: none;
        padding: 8px 16px;
        border-radius: 8px;
        font-size: 0.82rem;
        font-weight: 600;
        box-shadow: 0 2px 6px rgba(28,28,29,0.25);
        transition: all 0.15s;
        white-space: nowrap;
    }
    .view-dropdown-btn:hover,
    .view-dropdown-btn:focus {
        background: #2c2c2d;
        color: white;
        box-shadow: 0 4px 12px rgba(28,28,29,0.35);
    }
    .view-dropdown-btn::after {
        margin-left: 4px;
        border-top-color: #fff;
    }
    .view-dropdown-btn i.view-icon { font-size: 0.85rem; }

    .view-dropdown-menu {
        min-width: 280px;
        padding: 6px;
        border: none;
        border-radius: 10px;
        box-shadow: 0 6px 20px rgba(0,0,0,0.12);
        font-size: 0.82rem;
    }
    .view-dropdown-menu .view-option {
        display: flex;
        align-items: flex-start;
        gap: 10px;
        padding: 9px 11px;
        border-radius: 6px;
        color: #2c3e50;
        text-decoration: none;
        transition: background 0.12s;
    }
    .view-dropdown-menu .view-option:hover { background: #f2f6fb; color: #2c3e50; }
    .view-dropdown-menu .view-option i.view-opt-icon {
        font-size: 1rem; margin-top: 2px; width: 18px;
        text-align: center; flex-shrink: 0; color: #1c1c1d;
    }
    .view-dropdown-menu .view-option-body { min-width: 0; flex: 1; }
    .view-dropdown-menu .view-option-title {
        font-weight: 600; font-size: 0.82rem; line-height: 1.2;
    }
    .view-dropdown-menu .view-option-sub {
        font-size: 0.7rem; color: #6c757d; margin-top: 2px; line-height: 1.3;
    }
    .view-dropdown-menu .view-option .count-badge {
        font-size: .65rem; font-weight: 700; padding: 2px 8px;
        border-radius: 10px; background: #fdecee; color: #842029;
        margin-left: auto; margin-top: 2px; flex-shrink: 0;
    }

    /* ============================================================ */
    /* ACTION TOOLBAR + TABLE                                        */
    /* ============================================================ */
    .action-toolbar {
        display: flex; justify-content: space-between; align-items: center;
        margin-bottom: 20px; flex-wrap: wrap; gap: 15px;
        position: sticky; top: 60px; background: #f0f2f5; z-index: 99;
        padding-top: 10px; padding-bottom: 10px;
    }
    .search-group {
        display: flex; gap: 10px; align-items: center; flex: 1; max-width: 500px;
    }
    .search-group form { width: 100%; }
    .search-group .search-input {
        flex: 1; height: 38px; border: 1px solid #dee2e6;
        border-radius: 8px; padding: 8px 15px; font-size: 14px;
        transition: all 0.3s; background: #fff;
    }
    .search-group .search-input:focus {
        border-color: #1c1c1d; outline: none;
        box-shadow: 0 0 0 2px rgba(28,28,29,0.1);
    }
    .search-group .btn-search {
        height: 38px; padding: 0 18px;
        background: #1c1c1d; color: white; border: none;
        border-radius: 8px; transition: all 0.3s;
    }
    .search-group .btn-search:hover { background: #2c2c2d; transform: translateY(-1px); }

    .btn-primary { background: #1c1c1d; border-color: #1c1c1d; }
    .btn-primary:hover, .btn-primary:focus, .btn-primary:active {
        background: #2c2c2d !important; border-color: #2c2c2d !important;
    }
    .btn-outline-primary { color: #1c1c1d; border-color: #1c1c1d; }
    .btn-outline-primary:hover { background: #1c1c1d; border-color: #1c1c1d; color: #fff; }

    .card {
        border: none; border-radius: 10px;
        box-shadow: 0 2px 6px rgba(0,0,0,0.06);
        background: white; margin-bottom: 20px;
    }
    .table { margin-bottom: 0; width: 100%; font-size: 13px; }
    .table thead th {
        background: #f8f9fa; padding: 12px 12px;
        font-weight: 600; font-size: 12px;
        text-transform: uppercase; letter-spacing: 0.5px;
        color: #495057; border-bottom: 2px solid #dee2e6;
        white-space: nowrap;
    }
    .table tbody td {
        padding: 10px 12px; vertical-align: middle;
        border-bottom: 1px solid #e9ecef;
    }
    .table tbody tr:hover { background-color: rgba(28,28,29,0.04); }

    .photo-thumb {
        width: 60px; height: 60px; border-radius: 8px;
        object-fit: cover; cursor: pointer;
        background: #f0f0f0; border: 1px solid #e9ecef;
    }
    .photo-thumb-placeholder {
        width: 60px; height: 60px; border-radius: 8px;
        background: #f0f0f0; display: inline-flex;
        align-items: center; justify-content: center;
        color: #adb5bd; font-size: 22px;
    }
    .photo-preview-large {
        max-width: 100%; max-height: 400px;
        border-radius: 10px; border: 2px solid #dee2e6;
    }

    .pagination-wrapper {
        background: white; padding: 15px 20px;
        border-top: 1px solid #e9ecef;
        display: flex; justify-content: space-between; align-items: center;
        flex-wrap: wrap; gap: 15px;
    }
    .pagination-info { font-size: 13px; color: #6c757d; }
    .pagination { margin: 0; gap: 4px; }
    .pagination .page-link {
        color: #1c1c1d; padding: 6px 12px; font-size: 13px;
        border-radius: 6px; border: 1px solid #dee2e6; transition: all 0.2s;
    }
    .pagination .page-link:hover {
        background-color: #1c1c1d; color: #fff;
        border-color: #1c1c1d; transform: translateY(-1px);
    }
    .pagination .page-item.active .page-link {
        background-color: #1c1c1d; border-color: #1c1c1d; color: white;
    }
    .pagination .page-item.disabled .page-link {
        color: #adb5bd; cursor: not-allowed; transform: none; background: #f8f9fa;
    }

    /* Dropdown fix */
    .card.shadow-sm { min-height: 400px; }
    .table-responsive { overflow: visible !important; }
    @media (max-width: 768px) {
        .table-responsive { overflow-x: auto !important; overflow-y: visible !important; }
    }
    .dropdown-menu { z-index: 2000 !important; }
    .dropdown-menu.drop-up {
        top: auto !important; bottom: 100% !important;
        margin-bottom: .25rem; margin-top: 0;
    }

    /* ============================================================ */
    /* REJECTION MODAL — fixed height + scrollable body (BLACK)      */
    /* ============================================================ */
    #rejectModal .modal-dialog {
        max-height: 90vh !important;
        height: 90vh !important;
        margin: 5vh auto !important;
    }
    #rejectModal .modal-content {
        height: 100% !important;
        max-height: 100% !important;
        display: flex !important;
        flex-direction: column !important;
        overflow: hidden !important;
    }
    #rejectModal .modal-header,
    #rejectModal .modal-footer { flex-shrink: 0 !important; }
    #rejectModal .modal-body {
        max-height: calc(90vh - 130px) !important;
        overflow-y: scroll !important;
        overflow-x: hidden !important;
        -webkit-overflow-scrolling: touch;
        flex: 1 1 auto;
    }
    #rejectModal #reasonPicker { padding-right: 4px; }
    #rejectModal .modal-body::-webkit-scrollbar { width: 8px; }
    #rejectModal .modal-body::-webkit-scrollbar-thumb {
        background: #ced4da; border-radius: 4px;
    }
    #rejectModal .modal-body::-webkit-scrollbar-thumb:hover { background: #adb5bd; }

    .reject-modal .modal-header {
        background: #1c1c1d !important;
        color: #fff !important;
        border-bottom: 1px solid rgba(255,255,255,.08);
    }
    .reject-modal .modal-header .modal-title i { color: #dc3545; }
    .reject-modal .applicant-summary {
        background: #f8f9fa; border-radius: 8px;
        padding: 14px 16px; margin-bottom: 18px;
        border-left: 4px solid #1c1c1d;
    }
    .reject-modal .applicant-summary .row-line {
        display: flex; gap: 8px; font-size: .85rem; margin-bottom: 4px;
    }
    .reject-modal .applicant-summary .row-line .lbl {
        color: #6c757d; min-width: 90px; font-weight: 600;
    }
    .reject-modal .applicant-summary .row-line .val {
        color: #2c3e50; word-break: break-word;
    }
    .reject-modal .reason-group-title {
        font-size: .7rem; font-weight: 700;
        text-transform: uppercase; letter-spacing: .5px;
        color: #8896a6; margin: 12px 0 6px; padding-left: 2px;
    }
    .reject-modal .reason-option {
        display: flex; align-items: flex-start; gap: 10px;
        padding: 9px 12px; border-radius: 8px;
        border: 1px solid #e9ecef; margin-bottom: 6px;
        cursor: pointer; transition: all .15s; background: #fff;
    }
    .reject-modal .reason-option:hover {
        background: #f8f9fa; border-color: #ced4da;
    }
    .reject-modal .reason-option input[type="radio"] {
        margin-top: 3px; flex-shrink: 0; accent-color: #1c1c1d;
    }
    .reject-modal .reason-option.selected {
        background: #f1f3f5; border-color: #1c1c1d;
        box-shadow: 0 0 0 2px rgba(28,28,29,.12);
    }
    .reject-modal .reason-option .label {
        font-size: .88rem; font-weight: 500;
        color: #2c3e50; margin: 0; line-height: 1.3;
    }
    .reject-modal .reason-option .preview {
        font-size: .74rem; color: #8896a6; margin-top: 2px; line-height: 1.35;
    }
    .reject-modal .custom-reason-box {
        display: none; margin-top: 12px;
        padding: 14px; background: #f8f9fa;
        border-radius: 8px; border-left: 4px solid #1c1c1d;
    }
    .reject-modal .custom-reason-box.show { display: block; }
    .reject-modal .custom-reason-box textarea {
        resize: vertical; min-height: 90px; font-size: .88rem;
    }
    .reject-modal .char-counter {
        font-size: .72rem; color: #8896a6;
        text-align: right; margin-top: 4px;
    }
    .reject-modal .char-counter.warn { color: #dc3545; font-weight: 600; }
    .reject-modal .preview-panel {
        background: #1c1c1d; color: #e9ecef;
        border-radius: 8px; padding: 14px 16px;
        font-size: .78rem; margin-top: 14px;
        max-height: 220px; overflow-y: auto;
    }
    .reject-modal .preview-panel .preview-label {
        text-transform: uppercase; letter-spacing: .5px;
        font-size: .68rem; color: #adb5bd;
        margin-bottom: 6px; font-weight: 700;
    }
    .reject-modal .preview-panel .preview-subject {
        color: #ffc107; font-weight: 600; margin-bottom: 8px;
    }
    .reject-modal .preview-panel .preview-body {
        color: #dee2e6; white-space: pre-wrap;
        font-family: 'Segoe UI', Tahoma, sans-serif;
    }
    .reject-modal .channels-row {
        display: flex; gap: 18px; margin-top: 12px;
        padding: 12px 14px; background: #f8f9fa;
        border-radius: 8px; font-size: .82rem;
    }
    .reject-modal .channels-row .ch {
        display: flex; align-items: center; gap: 6px; color: #495057;
    }
    .reject-modal .channels-row .ch i { color: #28a745; }
    .reject-modal .btn-reject-confirm {
        background: #1c1c1d; border: none; color: #fff;
        font-weight: 600; padding: 8px 22px; border-radius: 8px;
    }
    .reject-modal .btn-reject-confirm:hover { background: #2c2c2d; }
    .reject-modal .btn-reject-confirm:disabled {
        background: #e9ecef; color: #adb5bd; cursor: not-allowed;
    }

    @media (max-width: 768px) {
        #sidebar-wrapper {
            position: fixed; left: 0; top: 0;
            transform: translateX(-100%); z-index: 1050;
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
            <a href="pending_users.php" class="list-group-item active">
                <i class="fas fa-user-clock"></i><span>Pending</span>
                <?php if ($total_pending > 0): ?>
                    <span class="badge bg-danger badge-count"><?php echo $total_pending; ?></span>
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
                <span class="navbar-brand"><i class="fas fa-camera me-2"></i>Pending Photo Requests</span>
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
            <!-- TOP ROW: formal sub-tabs (right) + rejections dropdown        -->
            <!-- ============================================================ -->
            <div class="top-row">

                <div></div>

                <div style="display:flex; align-items:center; gap:18px; flex-wrap:wrap;">

                    <div class="page-tabs">
                        <a href="pending_users.php" class="tab">
                            User Registrations
                            <?php if ($user_pending_count > 0): ?>
                                <span class="tab-count"><?php echo $user_pending_count; ?></span>
                            <?php endif; ?>
                        </a>
                        <a href="pending_photos.php" class="tab active">
                            Photo Requests
                            <?php if ($photo_pending_count > 0): ?>
                                <span class="tab-count danger"><?php echo $photo_pending_count; ?></span>
                            <?php endif; ?>
                        </a>
                    </div>

                    <div class="dropdown">
                        <button class="view-dropdown-btn dropdown-toggle" type="button" id="rejectionsDropdownBtn" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="fas fa-user-times view-icon"></i>
                            <span>Rejected list</span>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end view-dropdown-menu" aria-labelledby="rejectionsDropdownBtn">
                            <li>
                                <a class="view-option" href="rejections_list.php?type=user">
                                    <i class="fas fa-user-slash view-opt-icon" style="color:#dc3545;"></i>
                                    <div class="view-option-body">
                                        <div class="view-option-title">Rejected User Registrations</div>
                                        <div class="view-option-sub">Applications that were not approved</div>
                                    </div>
                                    <?php if ($rejection_count > 0): ?>
                                        <span class="count-badge"><?php echo $rejection_count; ?></span>
                                    <?php endif; ?>
                                </a>
                            </li>
                            <li>
                                <a class="view-option" href="rejections_list.php?type=photo">
                                    <i class="fas fa-camera-retro view-opt-icon" style="color:#dc3545;"></i>
                                    <div class="view-option-body">
                                        <div class="view-option-title">Rejected Photo Requests</div>
                                        <div class="view-option-sub">Photo changes that were not approved</div>
                                    </div>
                                    <?php if ($photo_rejection_count > 0): ?>
                                        <span class="count-badge"><?php echo $photo_rejection_count; ?></span>
                                    <?php endif; ?>
                                </a>
                            </li>
                        </ul>
                    </div>

                </div>

            </div>

            <!-- Search toolbar -->
            <div class="action-toolbar">
                <div class="search-group">
                    <form method="GET" class="d-flex w-100 gap-2">
                        <input type="hidden" name="limit" value="<?php echo $limit; ?>">
                        <input type="text" name="search" class="search-input"
                               placeholder="Search by name, username or member code..."
                               value="<?php echo htmlspecialchars($search); ?>">
                        <button type="submit" class="btn-search"><i class="fas fa-search"></i> Search</button>
                    </form>
                </div>
            </div>

            <!-- Table -->
            <div class="card shadow-sm">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0 align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Member Code</th>
                                    <th>New Photo</th>
                                    <th>Name</th>
                                    <th>Username</th>
                                    <th>Reason for Change</th>
                                    <th>Requested</th>
                                    <th style="width:80px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (empty($requests)): ?>
                                <tr><td colspan="7" class="text-center py-5">
                                    <i class="fas fa-check-circle fa-3x text-success mb-3"></i>
                                    <p class="text-muted mb-0">
                                        <?php echo $search ? 'No photo requests match your search.' : 'No pending photo requests.'; ?>
                                    </p>
                                </td></tr>
                            <?php else: foreach ($requests as $r): ?>
                                <tr>
                                    <td><span class="badge bg-secondary"><?php echo htmlspecialchars($r['member_code'] ?? 'N/A'); ?></span></td>
                                    <td>
                                        <?php if (!empty($r['requested_photo'])): ?>
                                            <img src="../<?php echo htmlspecialchars($r['requested_photo']); ?>"
                                                 class="photo-thumb"
                                                 onclick="previewPhoto('<?php echo htmlspecialchars('../' . $r['requested_photo']); ?>', '<?php echo htmlspecialchars($r['full_name'] ?? ''); ?>')"
                                                 title="Click to preview">
                                        <?php else: ?>
                                            <div class="photo-thumb-placeholder"><i class="fas fa-image"></i></div>
                                        <?php endif; ?>
                                    </td>
                                    <td><strong><?php echo htmlspecialchars($r['full_name'] ?? ''); ?></strong></td>
                                    <td><?php echo htmlspecialchars($r['username'] ?? ''); ?></td>
                                    <td>
                                        <?php if (!empty($r['reason'])): ?>
                                            <small class="text-muted"><?php echo htmlspecialchars(mb_strimwidth($r['reason'], 0, 60, '…')); ?></small>
                                        <?php else: ?>
                                            <small class="text-muted fst-italic">No reason given</small>
                                        <?php endif; ?>
                                    </td>
                                    <td><small><?php echo date('M d, Y H:i', strtotime($r['requested_at'])); ?></small></td>
                                    <td>
                                        <div class="dropdown">
                                            <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="dropdown" aria-expanded="false">
                                                <i class="fas fa-ellipsis-v"></i>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-end">
                                                <li>
                                                    <a class="dropdown-item" href="#" onclick="previewPhoto('<?php echo htmlspecialchars('../' . ($r['requested_photo'] ?? '')); ?>', '<?php echo htmlspecialchars($r['full_name'] ?? ''); ?>'); return false;">
                                                        <i class="fas fa-eye text-info me-2"></i>View Photo
                                                    </a>
                                                </li>
                                                <li><hr class="dropdown-divider"></li>
                                                <li>
                                                    <form method="POST" style="display:block;">
                                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                                        <input type="hidden" name="request_id" value="<?php echo (int)$r['id']; ?>">
                                                        <input type="hidden" name="page" value="<?php echo $page; ?>">
                                                        <input type="hidden" name="limit" value="<?php echo $limit; ?>">
                                                        <button type="submit" name="action" value="approve" class="dropdown-item text-success"
                                                                onclick="return confirm('Approve this photo? It will replace the member\'s current profile picture.');">
                                                            <i class="fas fa-check-circle me-2"></i>Approve
                                                        </button>
                                                    </form>
                                                </li>
                                                <li>
                                                    <button type="button" class="dropdown-item text-warning"
                                                            onclick='openRejectModal(<?php echo json_encode([
                                                                "id"             => (int)$r["id"],
                                                                "full_name"      => $r["full_name"] ?? "",
                                                                "username"       => $r["username"] ?? "",
                                                                "email"          => $r["email"] ?? "",
                                                                "contact_number" => $r["contact_number"] ?? "",
                                                                "member_code"    => $r["member_code"] ?? "",
                                                            ], JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'>
                                                        <i class="fas fa-times-circle me-2"></i>Reject…
                                                    </button>
                                                </li>
                                                <li>
                                                    <form method="POST" style="display:block;">
                                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                                        <input type="hidden" name="request_id" value="<?php echo (int)$r['id']; ?>">
                                                        <input type="hidden" name="page" value="<?php echo $page; ?>">
                                                        <input type="hidden" name="limit" value="<?php echo $limit; ?>">
                                                        <button type="submit" name="action" value="delete" class="dropdown-item text-danger"
                                                                onclick="return confirm('Delete this request permanently?');">
                                                            <i class="fas fa-trash-alt me-2"></i>Delete
                                                        </button>
                                                    </form>
                                                </li>
                                            </ul>
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
                                    <a class="page-link" href="<?php echo buildPhotoUrl(1, $limit, $search); ?>"><i class="fas fa-angle-double-left"></i></a>
                                </li>
                                <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="<?php echo buildPhotoUrl($page - 1, $limit, $search); ?>"><i class="fas fa-chevron-left"></i></a>
                                </li>
                                <?php
                                $start = max(1, $page - 2);
                                $end   = min($total_pages, $page + 2);
                                for ($i = $start; $i <= $end; $i++):
                                ?>
                                <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                    <a class="page-link" href="<?php echo buildPhotoUrl($i, $limit, $search); ?>"><?php echo $i; ?></a>
                                </li>
                                <?php endfor; ?>
                                <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="<?php echo buildPhotoUrl($page + 1, $limit, $search); ?>"><i class="fas fa-chevron-right"></i></a>
                                </li>
                                <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="<?php echo buildPhotoUrl($total_pages, $limit, $search); ?>"><i class="fas fa-angle-double-right"></i></a>
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

<!-- Photo Preview Modal -->
<div class="modal fade" id="photoPreviewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white py-2">
                <h5 class="modal-title"><i class="fas fa-image me-2"></i>Photo Preview <span id="photoPreviewName" class="text-warning"></span></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center">
                <img id="photoPreviewImg" src="" alt="Photo" class="photo-preview-large">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Rejection Modal -->
<div class="modal fade reject-modal" id="rejectModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" id="rejectForm">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="action" value="reject">
                <input type="hidden" name="request_id" id="rejectRequestId" value="0">
                <input type="hidden" name="page" value="<?php echo $page; ?>">
                <input type="hidden" name="limit" value="<?php echo $limit; ?>">

                <div class="modal-header py-3">
                    <h5 class="modal-title"><i class="fas fa-user-times me-2"></i>Reject Photo Request</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">

                    <div class="applicant-summary">
                        <div class="row-line"><span class="lbl">Name:</span><span class="val" id="rName">—</span></div>
                        <div class="row-line"><span class="lbl">Username:</span><span class="val" id="rUsername">—</span></div>
                        <div class="row-line"><span class="lbl">Email:</span><span class="val" id="rEmail">—</span></div>
                        <div class="row-line"><span class="lbl">Contact:</span><span class="val" id="rContact">—</span></div>
                        <div class="row-line"><span class="lbl">Member #:</span><span class="val" id="rMemberCode">—</span></div>
                    </div>

                    <label class="form-label fw-semibold mb-1" style="font-size:.9rem;">
                        Select Rejection Reason <span class="text-danger">*</span>
                    </label>
                    <div id="reasonPicker">
                        <?php
                        $grouped = PhotoRejectionReasons::grouped();
                        foreach ($grouped as $groupName => $rows):
                        ?>
                            <div class="reason-group-title"><?php echo htmlspecialchars($groupName); ?></div>
                            <?php foreach ($rows as $code => $row): ?>
                                <label class="reason-option" data-code="<?php echo htmlspecialchars($code); ?>">
                                    <input type="radio" name="reason_code" value="<?php echo htmlspecialchars($code); ?>" required>
                                    <div>
                                        <p class="label"><?php echo htmlspecialchars($row['label']); ?></p>
                                        <div class="preview"><?php echo htmlspecialchars($row['message']); ?></div>
                                    </div>
                                </label>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    </div>

                    <div class="custom-reason-box" id="customReasonBox">
                        <label class="form-label fw-semibold" style="font-size:.85rem;">
                            <i class="fas fa-pencil-alt me-1"></i>Custom reason
                        </label>
                        <textarea class="form-control" name="custom_reason" id="customReason"
                                  maxlength="500"
                                  placeholder="Explain briefly why the photo is being rejected…"></textarea>
                        <div class="char-counter" id="charCounter">0 / 500</div>
                    </div>

                    <div class="preview-panel" id="previewPanel">
                        <div class="preview-label"><i class="fas fa-envelope me-1"></i>Member will receive:</div>
                        <div class="preview-subject" id="pvSubject">Subject: Your photo change request — NHGL</div>
                        <div class="preview-body" id="pvBody">Choose a reason above to see the message preview…</div>
                    </div>

                    <div class="channels-row">
                        <div class="ch"><i class="fas fa-check-circle"></i> In-app notification</div>
                        <div class="ch"><i class="fas fa-check-circle"></i> Email</div>
                        <div class="ch"><i class="fas fa-check-circle"></i> SMS</div>
                    </div>

                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn-reject-confirm" id="btnRejectConfirm" disabled>
                        <i class="fas fa-paper-plane me-1"></i>Reject &amp; Notify Member
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

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

/* Auto-flip dropdowns */
document.querySelectorAll('.dropdown').forEach(function (dd) {
    dd.addEventListener('show.bs.dropdown', function () {
        const btn  = this.querySelector('[data-bs-toggle="dropdown"]');
        const rect = btn.getBoundingClientRect();
        const spaceBelow = window.innerHeight - rect.bottom;
        const menu = this.querySelector('.dropdown-menu');
        menu.classList.remove('drop-up');
        if (spaceBelow < 200) menu.classList.add('drop-up');
    });
});

/* Photo preview */
let photoModalInstance = null;
function previewPhoto(url, name) {
    document.getElementById('photoPreviewImg').src = url;
    document.getElementById('photoPreviewName').textContent = name ? '— ' + name : '';
    if (!photoModalInstance) {
        photoModalInstance = new bootstrap.Modal(document.getElementById('photoPreviewModal'));
    }
    photoModalInstance.show();
}

/* Rejection modal logic */
const PHOTO_REJECT_REASONS = <?php
echo json_encode(
    array_map(
        fn($row) => ['label' => $row['label'], 'message' => $row['message']],
        PhotoRejectionReasons::all()
    ),
    JSON_HEX_APOS | JSON_HEX_QUOT
);
?>;

let rejectModalInstance = null;

function openRejectModal(r) {
    document.getElementById('rName').textContent       = r.full_name      || '—';
    document.getElementById('rUsername').textContent   = r.username       || '—';
    document.getElementById('rEmail').textContent      = r.email          || '—';
    document.getElementById('rContact').textContent    = r.contact_number || '—';
    document.getElementById('rMemberCode').textContent = r.member_code    || '—';
    document.getElementById('rejectRequestId').value   = r.id;

    document.querySelectorAll('#reasonPicker .reason-option').forEach(opt => {
        opt.classList.remove('selected');
        opt.querySelector('input[type="radio"]').checked = false;
    });
    document.getElementById('customReasonBox').classList.remove('show');
    document.getElementById('customReason').value = '';
    updateCharCounter();
    document.getElementById('btnRejectConfirm').disabled = true;
    document.getElementById('pvBody').textContent = 'Choose a reason above to see the message preview…';

    if (!rejectModalInstance) {
        rejectModalInstance = new bootstrap.Modal(document.getElementById('rejectModal'));
    }
    rejectModalInstance.show();
}

document.querySelectorAll('#reasonPicker .reason-option').forEach(opt => {
    opt.addEventListener('click', function () {
        const code = this.dataset.code;
        this.querySelector('input[type="radio"]').checked = true;
        document.querySelectorAll('#reasonPicker .reason-option').forEach(o => o.classList.remove('selected'));
        this.classList.add('selected');

        const customBox = document.getElementById('customReasonBox');
        if (code === 'other') {
            customBox.classList.add('show');
            document.getElementById('customReason').focus();
        } else {
            customBox.classList.remove('show');
        }
        updatePreview();
    });
});

document.getElementById('customReason').addEventListener('input', function () {
    updateCharCounter();
    updatePreview();
});

function updateCharCounter() {
    const el = document.getElementById('customReason');
    const counter = document.getElementById('charCounter');
    const len = el.value.length;
    counter.textContent = `${len} / 500`;
    counter.classList.toggle('warn', len > 480);
}

function getSelectedReasonCode() {
    const checked = document.querySelector('#reasonPicker input[type="radio"]:checked');
    return checked ? checked.value : '';
}

function updatePreview() {
    const code = getSelectedReasonCode();
    const confirmBtn = document.getElementById('btnRejectConfirm');
    const bodyEl = document.getElementById('pvBody');

    if (!code) {
        confirmBtn.disabled = true;
        bodyEl.textContent = 'Choose a reason above to see the message preview…';
        return;
    }

    const reason = PHOTO_REJECT_REASONS[code] || { label: '', message: '' };
    let message = reason.message;

    if (code === 'other') {
        const custom = document.getElementById('customReason').value.trim();
        if (!custom) {
            confirmBtn.disabled = true;
            bodyEl.textContent = 'Type a custom reason to see the message preview…';
            return;
        }
        message = custom;
    }

    confirmBtn.disabled = false;

    bodyEl.textContent =
        `Dear [Member Name],\n\n` +
        `Your recent request to update your profile photo was not approved.\n\n` +
        `Reason: ${reason.label}\n` +
        `${message}\n\n` +
        `You may submit a new photo anytime from your profile settings.`;
}
</script>
</body>
</html>