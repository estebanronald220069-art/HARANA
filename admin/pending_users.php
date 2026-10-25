<?php
// admin/pending_users.php
require_once '../includes/config.php';
require_once '../includes/db_connection.php';
require_once '../includes/security.php';
require_once '../includes/auth.php';
require_once '../includes/user_functions.php';
require_once '../includes/rejection_reasons.php';
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
$total_pending = $user_pending_count + $photo_pending_count;

$rejection_count = 0;
$photo_rejection_count = 0;
try {
    $rejection_count       = (int)($db->getSingle("SELECT COUNT(*) as cnt FROM pending_user_rejections WHERE rejection_type = 'user'")['cnt'] ?? 0);
    $photo_rejection_count = (int)($db->getSingle("SELECT COUNT(*) as cnt FROM pending_user_rejections WHERE rejection_type = 'photo'")['cnt'] ?? 0);
} catch (Throwable $e) {
    // Fallback for databases without the rejection_type column yet
    try {
        $rejection_count = (int)($db->getSingle("SELECT COUNT(*) as cnt FROM pending_user_rejections")['cnt'] ?? 0);
    } catch (Throwable $e2) {}
}

$success_toast = '';
$error_toast   = '';

/* ============================================================
   FILTERS + PAGINATION
   ============================================================ */
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
$search        = isset($_GET['search'])        ? Security::sanitize($_GET['search'])        : '';
$filter_name   = isset($_GET['filter_name'])   ? Security::sanitize($_GET['filter_name'])   : '';
$filter_chapter= isset($_GET['filter_chapter'])? Security::sanitize($_GET['filter_chapter']): '';
$filter_barangay = isset($_GET['filter_barangay']) ? Security::sanitize($_GET['filter_barangay']) : '';
$filter_group  = isset($_GET['filter_group'])  ? Security::sanitize($_GET['filter_group'])  : '';

$allowed_limits = [10, 20, 30, 50, 100];
if (!in_array($limit, $allowed_limits, true)) $limit = 20;

$where_conditions = ["status = 'pending'"];
$params = [];
$types  = '';

if ($search !== '') {
    $search_terms = explode(' ', $search);
    $search_conditions = [];
    foreach ($search_terms as $term) {
        $term = trim($term);
        if ($term === '') continue;
        $search_conditions[] = "(first_name LIKE ? OR last_name LIKE ? OR CONCAT(first_name,' ',last_name) LIKE ? OR username LIKE ? OR email LIKE ? OR member_code LIKE ?)";
        $like = "%$term%";
        for ($i = 0; $i < 6; $i++) { $params[] = $like; $types .= 's'; }
    }
    if ($search_conditions) $where_conditions[] = '(' . implode(' OR ', $search_conditions) . ')';
}

if ($filter_name !== '') {
    $where_conditions[] = "(first_name LIKE ? OR last_name LIKE ? OR CONCAT(first_name,' ',last_name) LIKE ?)";
    $like_name = "%$filter_name%";
    $params[] = $like_name; $params[] = $like_name; $params[] = $like_name;
    $types .= 'sss';
}
if ($filter_chapter !== '') {
    $where_conditions[] = "chapter = ?"; $params[] = $filter_chapter; $types .= 's';
}
if ($filter_barangay !== '') {
    $where_conditions[] = "barangay = ?"; $params[] = $filter_barangay; $types .= 's';
}
if ($filter_group !== '') {
    $where_conditions[] = "group_name = ?"; $params[] = $filter_group; $types .= 's';
}

$where_clause = 'WHERE ' . implode(' AND ', $where_conditions);

$total_records = (int)($db->getSingle("SELECT COUNT(*) as total FROM pending_users $where_clause", $params, $types)['total'] ?? 0);
$total_pages = max(1, (int)ceil($total_records / $limit));
if ($page > $total_pages) $page = $total_pages;
$offset = ($page - 1) * $limit;

$sql = "SELECT * FROM pending_users $where_clause ORDER BY requested_at DESC LIMIT ? OFFSET ?";
$query_params = array_merge($params, [$limit, $offset]);
$pending_users = $db->getAll($sql, $query_params, $types . 'ii');

/* ============================================================
   FILTER LISTS
   ============================================================ */
$chapter_list = array_column(
    $db->getAll("SELECT DISTINCT chapter FROM pending_users WHERE chapter IS NOT NULL AND chapter != '' AND status='pending' ORDER BY chapter ASC"),
    'chapter'
);
$barangay_list = array_column(
    $db->getAll("SELECT DISTINCT barangay FROM pending_users WHERE barangay IS NOT NULL AND barangay != '' AND status='pending' ORDER BY barangay ASC"),
    'barangay'
);
$group_list = array_column(
    $db->getAll("SELECT DISTINCT group_name FROM pending_users WHERE group_name IS NOT NULL AND group_name != '' AND status='pending' ORDER BY group_name ASC"),
    'group_name'
);

$csrf_token = Security::generateCSRFToken();

/* ============================================================
   POST HANDLERS
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error_toast = 'Invalid security token.';
    } else {
        $action = $_POST['action'] ?? '';
        $pending_id = (int)($_POST['pending_id'] ?? 0);

        /* -------- APPROVE -------- */
        if ($action === 'approve') {
            $pending = $db->getSingle("SELECT * FROM pending_users WHERE id = ?", [$pending_id], 'i');

            if (!$pending) {
                $error_toast = 'Request not found.';
            } else {
                $db->getConnection()->begin_transaction();
                try {
                    if ($db->getSingle("SELECT user_id FROM users WHERE username = ?", [$pending['username']], 's')) {
                        throw new Exception('Username already exists: ' . $pending['username']);
                    }
                    if ($db->getSingle("SELECT user_id FROM users WHERE email = ?", [$pending['email']], 's')) {
                        throw new Exception('Email already exists: ' . $pending['email']);
                    }

                    $full_name = trim(($pending['first_name'] ?? '') . ' ' . ($pending['middle_name'] ?? '') . ' ' . ($pending['last_name'] ?? ''));
                    $full_name = preg_replace('/\s+/', ' ', $full_name);

                   if (!empty($pending['user_id'])) {
    // ── Joined-flow user: user row already exists ──
    $user_id = (int)$pending['user_id'];

    // Sanity: ensure still exists
    $existing_user = $db->getSingle("SELECT user_id FROM users WHERE user_id = ?", [$user_id], 'i');
    if (!$existing_user) throw new Exception('Linked user account no longer exists.');

    $db->execute(
        "UPDATE users
         SET account_status = 'approved',
             is_active = 1,
             full_name = ?,
             email = ?
         WHERE user_id = ?",
        [$full_name, $pending['email'], $user_id],
        'ssi'
    );
} else {
    // ── Legacy standalone applicant (no user_id) ──
    $insert_user = $db->execute(
        "INSERT INTO users (username, password, full_name, email, role, is_active, account_status, created_at)
         VALUES (?, ?, ?, ?, ?, 1, 'approved', NOW())",
        [$pending['username'], $pending['password'], $full_name, $pending['email'], $pending['role_requested'] ?? 'viewer'],
        'sssss'
    );
    if (!$insert_user) throw new Exception('Failed to create user account: ' . $db->getConnection()->error);
    $user_id = $db->getConnection()->insert_id;
}

                    // member_code uniqueness
                    $member_code = $pending['member_code'];
                    if ($db->getSingle("SELECT member_code FROM members WHERE member_code = ?", [$member_code], 's')) {
                        $member_code = date('Y') . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
                    }

                    // Address
                    $address_parts = [];
                    if (!empty($pending['street']))    $address_parts[] = $pending['street'];
                    if (!empty($pending['barangay']))  $address_parts[] = 'Brgy. ' . $pending['barangay'];
                    if (!empty($pending['city']))      $address_parts[] = $pending['city'];
                    if (!empty($pending['province']))  $address_parts[] = $pending['province'];
                    $address = implode(', ', $address_parts);

                    $father_name = trim(($pending['father_fname'] ?? '') . ' ' . ($pending['father_mname'] ?? '') . ' ' . ($pending['father_lname'] ?? ''));
                    $mother_name = trim(($pending['mother_fname'] ?? '') . ' ' . ($pending['mother_mname'] ?? '') . ' ' . ($pending['mother_lname'] ?? ''));
                    $spouse_name = trim(($pending['spouse_fname'] ?? '') . ' ' . ($pending['spouse_mname'] ?? '') . ' ' . ($pending['spouse_lname'] ?? ''));

                    $child1_name = trim(($pending['child1_fname'] ?? '') . ' ' . ($pending['child1_mname'] ?? '') . ' ' . ($pending['child1_lname'] ?? ''));
                    $child2_name = trim(($pending['child2_fname'] ?? '') . ' ' . ($pending['child2_mname'] ?? '') . ' ' . ($pending['child2_lname'] ?? ''));
                    $child3_name = trim(($pending['child3_fname'] ?? '') . ' ' . ($pending['child3_mname'] ?? '') . ' ' . ($pending['child3_lname'] ?? ''));
                    $child4_name = trim(($pending['child4_fname'] ?? '') . ' ' . ($pending['child4_mname'] ?? '') . ' ' . ($pending['child4_lname'] ?? ''));

                    // ---- Ensure destination folders exist ----
                    $uploads_members_dir = '../uploads/members/';
                    if (!is_dir($uploads_members_dir)) {
                        @mkdir($uploads_members_dir, 0755, true);
                    }

                    // ---- Move files ----
                    $registration_photo = null;
                    if (!empty($pending['registration_photo'])) {
                        $old = '../' . $pending['registration_photo'];
                        if (file_exists($old)) {
                            $ext = strtolower(pathinfo($old, PATHINFO_EXTENSION));
                            $new = 'member_' . $member_code . '_' . time() . '.' . $ext;
                            $dest = '../uploads/members/' . $new;
                            if (rename($old, $dest)) $registration_photo = 'uploads/members/' . $new;
                        }
                    }

                    $medical_file_path = null;
                    if (!empty($pending['medical_certificate_file'])) {
                        $old = '../' . $pending['medical_certificate_file'];
                        if (file_exists($old)) {
                            $ext = strtolower(pathinfo($old, PATHINFO_EXTENSION));
                            $new = 'medical_' . $member_code . '_' . time() . '.' . $ext;
                            $dest = '../uploads/members/' . $new;
                            if (rename($old, $dest)) $medical_file_path = 'uploads/members/' . $new;
                        }
                    }

                    $birth_file_path = null;
                    if (!empty($pending['birth_certificate_file'])) {
                        $old = '../' . $pending['birth_certificate_file'];
                        if (file_exists($old)) {
                            $ext = strtolower(pathinfo($old, PATHINFO_EXTENSION));
                            $new = 'birth_' . $member_code . '_' . time() . '.' . $ext;
                            $dest = '../uploads/members/' . $new;
                            if (rename($old, $dest)) $birth_file_path = 'uploads/members/' . $new;
                        }
                    }

                    /* -------- INSERT INTO members -------- */
                    $insert_member_sql = "INSERT INTO members (
                        member_code, first_name, last_name, middle_name,
                        address, present_address, permanent_address,
                        barangay, city, province,
                        contact_number, alternate_number, email,
                        birth_date, place_of_birth, age, gender, civil_status, religion,
                        father_fname, father_mname, father_lname,
                        mother_fname, mother_mname, mother_lname,
                        spouse_fname, spouse_mname, spouse_lname,
                        father_name, mother_name, spouse_name, spouse_age,
                        child1_fname, child1_mname, child1_lname, child1_name, child1_age,
                        child2_fname, child2_mname, child2_lname, child2_name, child2_age,
                        child3_fname, child3_mname, child3_lname, child3_name, child3_age,
                        child4_fname, child4_mname, child4_lname, child4_name, child4_age,
                        street,
                        ref1_name, ref1_contact, ref2_name, ref2_contact,
                        date_joined, status,
                        monthly_contribution,
                        chapter, group_name, leader, coordinator, chairman,
                        screening_officer, screening_date, approved_by,
                        date_registered,
                        beneficiary_name, beneficiary_address, beneficiary_relation, beneficiary_age, beneficiary_contact,
                        medical_certificate, medical_certificate_file,
                        birth_certificate, birth_certificate_file,
                        profile_photo, username, user_id, created_by,
                        created_at
                    ) VALUES (
                        ?, ?, ?, ?,
                        ?, ?, ?,
                        ?, ?, ?,
                        ?, ?, ?,
                        ?, ?, ?, ?, ?, ?,
                        ?, ?, ?,
                        ?, ?, ?,
                        ?, ?, ?,
                        ?, ?, ?, ?,
                        ?, ?, ?, ?, ?,
                        ?, ?, ?, ?, ?,
                        ?, ?, ?, ?, ?,
                        ?, ?, ?, ?, ?,
                        ?,
                        ?, ?, ?, ?,
                        ?, 'active',
                        ?,
                        ?, ?, ?, ?, ?,
                        ?, ?, ?,
                        ?,
                        ?, ?, ?, ?, ?,
                        ?, ?,
                        ?, ?,
                        ?, ?, ?, ?,
                        NOW()
                    )";

                    $member_values = [
                        $member_code, $pending['first_name'], $pending['last_name'], $pending['middle_name'] ?? '',
                        $address, $pending['present_address'] ?? $address, $pending['permanent_address'] ?? $address,
                        $pending['barangay'] ?? '', $pending['city'] ?? 'San Jose', $pending['province'] ?? 'Occidental Mindoro',
                        $pending['contact_number'] ?? '', $pending['alternate_number'] ?? '', $pending['email'],
                        $pending['birth_date'] ?? null, $pending['place_of_birth'] ?? '', $pending['age'] ?? 0,
                        $pending['gender'] ?? '', $pending['civil_status'] ?? '', $pending['religion'] ?? '',
                        $pending['father_fname'] ?? '', $pending['father_mname'] ?? '', $pending['father_lname'] ?? '',
                        $pending['mother_fname'] ?? '', $pending['mother_mname'] ?? '', $pending['mother_lname'] ?? '',
                        $pending['spouse_fname'] ?? '', $pending['spouse_mname'] ?? '', $pending['spouse_lname'] ?? '',
                        $father_name, $mother_name, $spouse_name, $pending['spouse_age'] ?? null,
                        $pending['child1_fname'] ?? '', $pending['child1_mname'] ?? '', $pending['child1_lname'] ?? '', $child1_name, $pending['child1_age'] ?? null,
                        $pending['child2_fname'] ?? '', $pending['child2_mname'] ?? '', $pending['child2_lname'] ?? '', $child2_name, $pending['child2_age'] ?? null,
                        $pending['child3_fname'] ?? '', $pending['child3_mname'] ?? '', $pending['child3_lname'] ?? '', $child3_name, $pending['child3_age'] ?? null,
                        $pending['child4_fname'] ?? '', $pending['child4_mname'] ?? '', $pending['child4_lname'] ?? '', $child4_name, $pending['child4_age'] ?? null,
                        $pending['street'] ?? '',
                        $pending['ref1_name'] ?? '', $pending['ref1_contact'] ?? '', $pending['ref2_name'] ?? '', $pending['ref2_contact'] ?? '',
                        $pending['date_joined'] ?? date('Y-m-d'),
                        $pending['monthly_contribution'] ?? 100.00,
                        $pending['chapter'] ?? '', $pending['group_name'] ?? '', $pending['leader'] ?? '', $pending['coordinator'] ?? '', $pending['chairman'] ?? '',
                        $pending['screening_officer'] ?? '', $pending['screening_date'] ?? null, $pending['approved_by'] ?? '',
                        $pending['date_registered'] ?? date('Y-m-d'),
                        $pending['beneficiary_name'] ?? '', $pending['beneficiary_address'] ?? '', $pending['beneficiary_relation'] ?? '', $pending['beneficiary_age'] ?? null, $pending['beneficiary_contact'] ?? '',
                        $pending['medical_certificate'] ?? 0, $medical_file_path,
                        $pending['birth_certificate'] ?? 0, $birth_file_path,
                        $registration_photo, $pending['username'], $user_id, $user_id
                    ];

                    if (!$db->execute($insert_member_sql, $member_values, str_repeat('s', count($member_values)))) {
                        throw new Exception('Failed to create member record: ' . $db->getConnection()->error);
                    }

                    // Init balances if table exists
                    if ($db->getSingle("SHOW TABLES LIKE 'member_balances'")) {
                        $db->execute(
                            "INSERT INTO member_balances (member_code, total_paid, total_due, current_balance)
                             VALUES (?, 0.00, ?, ?)",
                            [$member_code, $pending['monthly_contribution'] ?? 100.00, $pending['monthly_contribution'] ?? 100.00],
                            'sdd'
                        );
                    }

                    $db->execute("DELETE FROM pending_users WHERE id = ?", [$pending_id], 'i');
                    $db->getConnection()->commit();

                    Security::logEvent('USER_APPROVED', "Approved user: {$pending['username']}");

                    if (function_exists('createNotification')) {
                        createNotification($db, $user_id, "Account Approved",
                            "Your membership application has been approved! You can now log in to your account.",
                            'account', '../index.php');
                    }

                    // Send approval email
                    try {
                        if (class_exists('Mailer')) {
                           Mailer::sendApproval(
    $pending['email'],
    $pending['first_name'] ?? '',
    $pending['email']   // joined users log in with email
);
                        }
                    } catch (Throwable $e) {
                        error_log('Approval email failed: ' . $e->getMessage());
                    }

                    header('Location: pending_users.php?success=approved&page=' . $page . '&limit=' . $limit);
                    exit();

                } catch (Exception $e) {
                    $db->getConnection()->rollback();
                    $error_toast = 'Failed to approve user: ' . $e->getMessage();
                    error_log('Approval error: ' . $e->getMessage());
                }
            }
        }

        /* -------- REJECT (with reason) -------- */
        elseif ($action === 'reject') {
            $pending = $db->getSingle("SELECT * FROM pending_users WHERE id = ?", [$pending_id], 'i');

            if (!$pending) {
                $error_toast = 'Request not found.';
            } else {
                $reason_code   = (string)($_POST['reason_code']   ?? '');
                $custom_reason = (string)($_POST['custom_reason'] ?? '');

                $check = RejectionReasons::validate($reason_code, $custom_reason);

                if (!$check['ok']) {
                    $error_toast = $check['error'];
                } else {
                    $full_name = trim(($pending['first_name'] ?? '') . ' ' . ($pending['last_name'] ?? ''));
                    if ($full_name === '') $full_name = 'Applicant';

                    $insert_sql = "INSERT INTO pending_user_rejections
                        (pending_user_id, rejection_type, member_code, full_name, username, email, contact_number,
                         reason_code, reason_label, reason_message, custom_reason,
                         rejected_by, rejected_by_name, rejected_at,
                         notification_status, email_status, sms_status, delivery_log)
                        VALUES (?, 'user', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(),
                                'pending','pending','pending', NULL)";

                    $insert_ok = $db->execute($insert_sql, [
                        $pending_id,
                        $pending['member_code'] ?? null,
                        $full_name,
                        $pending['username']       ?? '',
                        $pending['email']          ?? '',
                        $pending['contact_number'] ?? null,
                        $check['reason_code'],
                        $check['reason_label'],
                        $check['reason_message'],
                        $check['custom_reason'] !== '' ? $check['custom_reason'] : null,
                        $current_user['user_id'],
                        $current_user['full_name'] ?? $current_user['username'],
                    ], 'isssssssssiss');

                    if (!$insert_ok) {
                        $error_toast = 'Failed to save rejection record: ' . $db->getConnection()->error;
                    } else {
                        $rejection_id = (int)$db->getConnection()->insert_id;

                        try {
                            $notify_result = Notifier::sendRejection(
                                $pending,
                                $check,
                                ['status_url' => Notifier::buildStatusUrl()]
                            );
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

                        foreach (['registration_photo','medical_certificate_file','birth_certificate_file'] as $col) {
                            if (!empty($pending[$col])) {
                                $p = '../' . $pending[$col];
                                if (file_exists($p)) @unlink($p);
                            }
                        }

                        $db->execute("UPDATE pending_users SET status = 'rejected' WHERE id = ?", [$pending_id], 'i');

                        Security::logEvent('USER_REJECTED',
                            "Rejected user {$pending['username']} — Reason: {$check['reason_label']}" .
                            ($check['custom_reason'] !== '' ? " | Custom: {$check['custom_reason']}" : '') .
                            " | Delivery: {$notify_result['overall']}"
                        );

                        header('Location: pending_users.php?success=rejected&page=' . $page . '&limit=' . $limit);
                        exit();
                    }
                }
            }
        }

        /* -------- DELETE -------- */
        elseif ($action === 'delete') {
            $pending = $db->getSingle("SELECT * FROM pending_users WHERE id = ?", [$pending_id], 'i');
            if ($pending) {
                foreach (['registration_photo','medical_certificate_file','birth_certificate_file'] as $col) {
                    if (!empty($pending[$col])) {
                        $p = '../' . $pending[$col];
                        if (file_exists($p)) @unlink($p);
                    }
                }
            }
            $db->execute("DELETE FROM pending_users WHERE id = ?", [$pending_id], 'i');
            Security::logEvent('USER_DELETED', "Deleted pending user ID: $pending_id");
            header('Location: pending_users.php?success=deleted&page=' . $page . '&limit=' . $limit);
            exit();
        }
    }
}

/* ============================================================
   FLASH MESSAGES
   ============================================================ */
if (isset($_GET['success'])) {
    $map = [
        'approved' => 'User approved and added to members list. They can now log in!',
        'rejected' => 'Request rejected and applicant notified.',
        'deleted'  => 'Request deleted permanently.',
    ];
    $success_toast = $map[$_GET['success']] ?? '';
}

function buildPendUrl($page, $limit, $search, $filter_name, $filter_chapter, $filter_barangay, $filter_group) {
    $q = ['page' => $page, 'limit' => $limit];
    if ($search         !== '') $q['search']         = $search;
    if ($filter_name    !== '') $q['filter_name']    = $filter_name;
    if ($filter_chapter !== '') $q['filter_chapter'] = $filter_chapter;
    if ($filter_barangay!== '') $q['filter_barangay']= $filter_barangay;
    if ($filter_group   !== '') $q['filter_group']   = $filter_group;
    return '?' . http_build_query($q);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pending Approvals - Harana Financial System</title>
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
    /* SIDEBAR                                                       */
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
    /* TOP ROW: sub-tabs (right) + rejections dropdown (right)       */
    /* ============================================================ */
    .top-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 15px;
        margin-bottom: 16px;
        flex-wrap: wrap;
    }

    /* Formal tab-style sub-nav (right aligned) */
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
    .page-tabs .tab:hover {
        color: #1c1c1d;
    }
    .page-tabs .tab.active {
        color: #1c1c1d;
        border-bottom-color: #1c1c1d;
    }
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
    .page-tabs .tab.active .tab-count {
        background: #1c1c1d;
        color: #fff;
    }
    .page-tabs .tab-count.danger {
        background: #fdecee;
        color: #842029;
    }
    .page-tabs .tab.active .tab-count.danger {
        background: #842029;
        color: #fff;
    }

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
    .view-dropdown-menu .view-option:hover {
        background: #f2f6fb;
        color: #2c3e50;
    }
    .view-dropdown-menu .view-option i.view-opt-icon {
        font-size: 1rem;
        margin-top: 2px;
        width: 18px;
        text-align: center;
        flex-shrink: 0;
        color: #1c1c1d;
    }
    .view-dropdown-menu .view-option-body { min-width: 0; flex: 1; }
    .view-dropdown-menu .view-option-title {
        font-weight: 600;
        font-size: 0.82rem;
        line-height: 1.2;
    }
    .view-dropdown-menu .view-option-sub {
        font-size: 0.7rem;
        color: #6c757d;
        margin-top: 2px;
        line-height: 1.3;
    }
    .view-dropdown-menu .view-option .count-badge {
        font-size: .65rem;
        font-weight: 700;
        padding: 2px 8px;
        border-radius: 10px;
        background: #fdecee;
        color: #842029;
        margin-left: auto;
        margin-top: 2px;
        flex-shrink: 0;
    }

    /* ============================================================ */
    /* ACTION TOOLBAR (search)                                       */
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

    /* Small photo thumb in table */
    .pending-thumb {
        width: 40px; height: 40px; border-radius: 8px;
        object-fit: cover; cursor: pointer;
        background: #f0f0f0; border: 1px solid #e9ecef;
    }
    .pending-thumb-placeholder {
        width: 40px; height: 40px; border-radius: 8px;
        background: #f0f0f0; display: inline-flex;
        align-items: center; justify-content: center;
        color: #adb5bd; font-size: 20px;
    }

    /* Detail modal internals */
    .logo-section { display:flex; align-items:center; gap:20px; padding:20px; background:linear-gradient(135deg,#f5f7fa 0%,#c3cfe2 100%); border-radius:12px; margin-bottom:20px; }
    .logo-section img { width:80px; height:80px; object-fit:contain; }
    .logo-placeholder { width:80px; height:80px; background:linear-gradient(135deg,#667eea 0%,#764ba2 100%); border-radius:50%; display:flex; align-items:center; justify-content:center; color:white; }
    .logo-section .text-content { flex:1; }
    .logo-section h2 { color:#2c3e50; margin-bottom:5px; font-size:18px; }
    .logo-section h5 { color:#34495e; margin-bottom:3px; font-size:13px; }
    .logo-section p  { margin-bottom:2px; color:#7f8c8d; font-size:10px; }
    .form-title { text-align:center; margin:20px 0; padding:10px; background:#1c1c1d; color:white; border-radius:8px; font-size:16px; }
    .documents-section { background:#e8f4f8; padding:10px 15px; border-radius:8px; margin-bottom:20px; }
    .checkbox-group { display:flex; gap:20px; margin-top:5px; flex-wrap:wrap; }
    .checkbox-item { display:flex; align-items:center; gap:5px; }
    .section-title { background:#1c1c1d; color:white; padding:6px 12px; border-radius:6px; margin:15px 0 10px 0; font-size:13px; font-weight:600; }
    .info-table { width:100%; margin-bottom:15px; }
    .info-table td { padding:8px; border:1px solid #dee2e6; vertical-align:top; }
    .info-table .label { font-weight:600; background-color:#f8f9fa; width:180px; }
    .pending-photo { width:150px; height:150px; object-fit:cover; border-radius:12px; border:3px solid #dee2e6; }
    .photo-section { text-align:center; margin-bottom:20px; padding:15px; background:#f8f9fa; border-radius:10px; }

    /* Modal header dark accent */
    .modal-header.bg-info {
        background: #1c1c1d !important;
        color: #fff !important;
    }

    /* ============================================================ */
    /* FIX 1: Dropdown clipping in tables                            */
    /* ============================================================ */
    .card.shadow-sm {
        min-height: 400px;
    }
    .table-responsive {
        overflow: visible !important;
    }
    @media (max-width: 768px) {
        .table-responsive {
            overflow-x: auto !important;
            overflow-y: visible !important;
        }
    }
    .dropdown-menu {
        z-index: 2000 !important;
    }
    .dropdown-menu.drop-up {
        top: auto !important;
        bottom: 100% !important;
        margin-bottom: .25rem;
        margin-top: 0;
    }

    /* ============================================================ */
    /* FIX 2: Rejection modal — force scroll on modal body           */
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
    #rejectModal .modal-footer {
        flex-shrink: 0 !important;
    }
    #rejectModal .modal-body {
        max-height: calc(90vh - 130px) !important;
        overflow-y: scroll !important;
        overflow-x: hidden !important;
        -webkit-overflow-scrolling: touch;
        flex: 1 1 auto;
    }
    #rejectModal #reasonPicker {
        padding-right: 4px;
    }
    #rejectModal .modal-body::-webkit-scrollbar {
        width: 8px;
    }
    #rejectModal .modal-body::-webkit-scrollbar-thumb {
        background: #ced4da;
        border-radius: 4px;
    }
    #rejectModal .modal-body::-webkit-scrollbar-thumb:hover {
        background: #adb5bd;
    }

    /* ============================================================ */
    /* REJECTION MODAL — BLACK THEME                                 */
    /* ============================================================ */
    .reject-modal .modal-header {
        background: #1c1c1d !important;
        color: #fff !important;
        border-bottom: 1px solid rgba(255,255,255,.08);
    }
    .reject-modal .modal-header .modal-title i {
        color: #dc3545;
    }
    .reject-modal .applicant-summary {
        background: #f8f9fa;
        border-radius: 8px;
        padding: 14px 16px;
        margin-bottom: 18px;
        border-left: 4px solid #1c1c1d;
    }
    .reject-modal .applicant-summary .row-line {
        display: flex;
        gap: 8px;
        font-size: .85rem;
        margin-bottom: 4px;
    }
    .reject-modal .applicant-summary .row-line .lbl {
        color: #6c757d;
        min-width: 90px;
        font-weight: 600;
    }
    .reject-modal .applicant-summary .row-line .val {
        color: #2c3e50;
        word-break: break-word;
    }
    .reject-modal .reason-group-title {
        font-size: .7rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .5px;
        color: #8896a6;
        margin: 12px 0 6px;
        padding-left: 2px;
    }
    .reject-modal .reason-option {
        display: flex;
        align-items: flex-start;
        gap: 10px;
        padding: 9px 12px;
        border-radius: 8px;
        border: 1px solid #e9ecef;
        margin-bottom: 6px;
        cursor: pointer;
        transition: all .15s;
        background: #fff;
    }
    .reject-modal .reason-option:hover {
        background: #f8f9fa;
        border-color: #ced4da;
    }
    .reject-modal .reason-option input[type="radio"] {
        margin-top: 3px;
        flex-shrink: 0;
        accent-color: #1c1c1d;
    }
    .reject-modal .reason-option.selected {
        background: #f1f3f5;
        border-color: #1c1c1d;
        box-shadow: 0 0 0 2px rgba(28,28,29,.12);
    }
    .reject-modal .reason-option .label {
        font-size: .88rem;
        font-weight: 500;
        color: #2c3e50;
        margin: 0;
        line-height: 1.3;
    }
    .reject-modal .reason-option .preview {
        font-size: .74rem;
        color: #8896a6;
        margin-top: 2px;
        line-height: 1.35;
    }
    .reject-modal .custom-reason-box {
        display: none;
        margin-top: 12px;
        padding: 14px;
        background: #f8f9fa;
        border-radius: 8px;
        border-left: 4px solid #1c1c1d;
    }
    .reject-modal .custom-reason-box.show { display: block; }
    .reject-modal .custom-reason-box textarea {
        resize: vertical;
        min-height: 90px;
        font-size: .88rem;
    }
    .reject-modal .char-counter {
        font-size: .72rem;
        color: #8896a6;
        text-align: right;
        margin-top: 4px;
    }
    .reject-modal .char-counter.warn { color: #dc3545; font-weight: 600; }
    .reject-modal .preview-panel {
        background: #1c1c1d;
        color: #e9ecef;
        border-radius: 8px;
        padding: 14px 16px;
        font-size: .78rem;
        margin-top: 14px;
        max-height: 220px;
        overflow-y: auto;
    }
    .reject-modal .preview-panel .preview-label {
        text-transform: uppercase;
        letter-spacing: .5px;
        font-size: .68rem;
        color: #adb5bd;
        margin-bottom: 6px;
        font-weight: 700;
    }
    .reject-modal .preview-panel .preview-subject {
        color: #ffc107;
        font-weight: 600;
        margin-bottom: 8px;
    }
    .reject-modal .preview-panel .preview-body {
        color: #dee2e6;
        white-space: pre-wrap;
        font-family: 'Segoe UI', Tahoma, sans-serif;
    }
    .reject-modal .channels-row {
        display: flex;
        gap: 18px;
        margin-top: 12px;
        padding: 12px 14px;
        background: #f8f9fa;
        border-radius: 8px;
        font-size: .82rem;
    }
    .reject-modal .channels-row .ch {
        display: flex;
        align-items: center;
        gap: 6px;
        color: #495057;
    }
    .reject-modal .channels-row .ch i { color: #28a745; }
    .reject-modal .btn-reject-confirm {
        background: #1c1c1d;
        border: none;
        color: #fff;
        font-weight: 600;
        padding: 8px 22px;
        border-radius: 8px;
    }
    .reject-modal .btn-reject-confirm:hover {
        background: #2c2c2d;
    }
    .reject-modal .btn-reject-confirm:disabled {
        background: #e9ecef;
        color: #adb5bd;
        cursor: not-allowed;
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
            <a href="leader_assignments.php" class="list-group-item <?php echo $current_page == 'leader_assignments.php' ? 'active' : ''; ?>"><i class="fas fa-user-shield"></i><span>Leader Assignment</span></a>
            <a href="council.php" class="list-group-item"><i class="fas fa-user-tie"></i><span>Council</span></a>
            <a href="payments.php" class="list-group-item"><i class="fas fa-credit-card"></i><span>Payments</span></a>
            <a href="remittances.php" class="list-group-item"><i class="fas fa-hand-holding-usd"></i><span>Remittances</span></a>
            <a href="reports.php" class="list-group-item"><i class="fas fa-chart-bar"></i><span>Reports</span></a>
            <a href="announcements.php" class="list-group-item"><i class="fas fa-bullhorn"></i><span>Send Announcement</span></a>
            <?php if ($current_user['role'] === 'admin'): ?>
            <a href="pending_users.php" class="list-group-item active">
                <i class="fas fa-user-clock"></i><span>Pending</span>
                <?php if ($total_pending > 0): ?>
                    <span class="badge bg-danger badge-count"><?php echo $total_pending; ?></span>
                <?php endif; ?>
            </a>
            <?php endif; ?>
            
            <a href="settings.php" class="list-group-item"><i class="fas fa-cog"></i><span>Settings</span></a>
            <a href="../logout.php" class="list-group-item" onclick="return confirm('Are you sure?');"><i class="fas fa-sign-out-alt"></i><span>Logout</span></a>
        </div>
    </div>

    <!-- Page content -->
    <div id="page-content-wrapper">
        <nav class="navbar navbar-light bg-light">
            <div class="navbar-left">
                <img src="../assets/images/harana-logo.png" alt="Harana" class="header-logo" id="headerLogo" onerror="this.style.display='none';">
                <span class="navbar-brand"><i class="fas fa-user-clock me-2"></i>Pending Approvals</span>
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

                <!-- Left spacer (keeps dropdown on the far right) -->
                <div></div>

                <!-- Sub-tabs (formal) + Rejections dropdown -->
                <div style="display:flex; align-items:center; gap:18px; flex-wrap:wrap;">

                    <div class="page-tabs">
                        <a href="pending_users.php" class="tab active">
                            User Registrations
                            <?php if ($user_pending_count > 0): ?>
                                <span class="tab-count"><?php echo $user_pending_count; ?></span>
                            <?php endif; ?>
                        </a>
                        <a href="pending_photos.php" class="tab">
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

            <!-- Action toolbar (search only) -->
            <div class="action-toolbar">
                <div class="search-group">
                    <form method="GET" class="d-flex w-100 gap-2">
                        <input type="hidden" name="limit" value="<?php echo $limit; ?>">
                        <input type="text" name="search" class="search-input"
                               placeholder="Search by name, username, email or member code..."
                               value="<?php echo htmlspecialchars($search); ?>">
                        <button type="submit" class="btn-search"><i class="fas fa-search"></i> Search</button>
                    </form>
                </div>
            </div>

            <!-- Filter bar -->
            <div class="filter-bar">
                <form method="GET" class="filter-row">
                    <input type="hidden" name="search" value="<?php echo htmlspecialchars($search); ?>">

                    <div class="filter-item">
                        <label>Name Filter</label>
                        <input type="text" name="filter_name" class="form-control"
                               placeholder="Filter by name" value="<?php echo htmlspecialchars($filter_name); ?>">
                    </div>

                    <div class="filter-item">
                        <label>Barangay</label>
                        <select name="filter_barangay" class="form-select">
                            <option value="">All Barangays</option>
                            <?php foreach ($barangay_list as $brgy): ?>
                                <option value="<?php echo htmlspecialchars($brgy); ?>" <?php echo $filter_barangay == $brgy ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($brgy); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-item">
                        <label>Chapter</label>
                        <select name="filter_chapter" class="form-select">
                            <option value="">All Chapters</option>
                            <?php foreach ($chapter_list as $chap): ?>
                                <option value="<?php echo htmlspecialchars($chap); ?>" <?php echo $filter_chapter == $chap ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($chap); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-item">
                        <label>Group</label>
                        <select name="filter_group" class="form-select">
                            <option value="">All Groups</option>
                            <?php foreach ($group_list as $grp): ?>
                                <option value="<?php echo htmlspecialchars($grp); ?>" <?php echo $filter_group == $grp ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($grp); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
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
                        <a href="pending_users.php" class="btn btn-secondary"><i class="fas fa-undo me-1"></i> Reset</a>
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
                                    <th>Member Code</th>
                                    <th>Photo</th>
                                    <th>Name</th>
                                    <th>Username</th>
                                    <th>Chapter</th>
                                    <th>Email</th>
                                    <th>Requested</th>
                                    <th style="width:80px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (empty($pending_users)): ?>
                                <tr><td colspan="8" class="text-center py-5">
                                    <i class="fas fa-check-circle fa-3x text-success mb-3"></i>
                                    <p class="text-muted mb-0">
                                        <?php echo $search || $filter_name || $filter_chapter || $filter_barangay || $filter_group
                                            ? 'No pending requests match your filters.'
                                            : 'No pending registration requests.'; ?>
                                    </p>
                                </td></tr>
                            <?php else: foreach ($pending_users as $u): ?>
                                <tr>
                                    <td><span class="badge bg-secondary"><?php echo htmlspecialchars($u['member_code'] ?? 'N/A'); ?></span></td>
                                    <td>
                                        <?php if (!empty($u['registration_photo'])): ?>
                                            <img src="../<?php echo htmlspecialchars($u['registration_photo']); ?>"
                                                 class="pending-thumb"
                                                 onclick="viewDetails(<?php echo (int)$u['id']; ?>)"
                                                 title="Click to view full details">
                                        <?php else: ?>
                                            <div class="pending-thumb-placeholder"><i class="fas fa-user-circle"></i></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')); ?></strong>
                                        <?php if (!empty($u['middle_name'])): ?>
                                            <br><small class="text-muted"><?php echo htmlspecialchars($u['middle_name']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($u['username']); ?></td>
                                    <td>
                                        <?php echo !empty($u['chapter'])
                                            ? '<span class="badge bg-info">' . htmlspecialchars($u['chapter']) . '</span>'
                                            : '<span class="text-muted">—</span>'; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($u['email']); ?></td>
                                    <td><small><?php echo date('M d, Y H:i', strtotime($u['requested_at'])); ?></small></td>
                                    <td>
                                        <div class="dropdown">
                                            <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="dropdown" aria-expanded="false">
                                                <i class="fas fa-ellipsis-v"></i>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-end">
                                                <li>
                                                    <a class="dropdown-item" href="#" onclick="viewDetails(<?php echo (int)$u['id']; ?>); return false;">
                                                        <i class="fas fa-eye text-info me-2"></i>View Details
                                                    </a>
                                                </li>
                                                <li><hr class="dropdown-divider"></li>
                                                <li>
                                                    <form method="POST" style="display:block;">
                                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                                        <input type="hidden" name="pending_id" value="<?php echo (int)$u['id']; ?>">
                                                        <input type="hidden" name="page" value="<?php echo $page; ?>">
                                                        <input type="hidden" name="limit" value="<?php echo $limit; ?>">
                                                        <button type="submit" name="action" value="approve" class="dropdown-item text-success"
                                                                onclick="return confirm('Approve this user? This will create a member record.');">
                                                            <i class="fas fa-check-circle me-2"></i>Approve
                                                        </button>
                                                    </form>
                                                </li>
                                                <li>
                                                    <button type="button" class="dropdown-item text-warning"
                                                            onclick='openRejectModal(<?php echo json_encode([
                                                                "id"             => (int)$u["id"],
                                                                "first_name"     => $u["first_name"]     ?? "",
                                                                "last_name"      => $u["last_name"]      ?? "",
                                                                "middle_name"    => $u["middle_name"]    ?? "",
                                                                "username"       => $u["username"]       ?? "",
                                                                "email"          => $u["email"]          ?? "",
                                                                "contact_number" => $u["contact_number"] ?? "",
                                                                "member_code"    => $u["member_code"]    ?? "",
                                                                "chapter"        => $u["chapter"]        ?? "",
                                                            ], JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'>
                                                        <i class="fas fa-times-circle me-2"></i>Reject…
                                                    </button>
                                                </li>
                                                <li>
                                                    <form method="POST" style="display:block;">
                                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                                        <input type="hidden" name="pending_id" value="<?php echo (int)$u['id']; ?>">
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
                                    <a class="page-link" href="<?php echo buildPendUrl(1, $limit, $search, $filter_name, $filter_chapter, $filter_barangay, $filter_group); ?>">
                                        <i class="fas fa-angle-double-left"></i>
                                    </a>
                                </li>
                                <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="<?php echo buildPendUrl($page - 1, $limit, $search, $filter_name, $filter_chapter, $filter_barangay, $filter_group); ?>">
                                        <i class="fas fa-chevron-left"></i>
                                    </a>
                                </li>
                                <?php
                                $start = max(1, $page - 2);
                                $end   = min($total_pages, $page + 2);
                                for ($i = $start; $i <= $end; $i++):
                                ?>
                                <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                    <a class="page-link" href="<?php echo buildPendUrl($i, $limit, $search, $filter_name, $filter_chapter, $filter_barangay, $filter_group); ?>"><?php echo $i; ?></a>
                                </li>
                                <?php endfor; ?>
                                <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="<?php echo buildPendUrl($page + 1, $limit, $search, $filter_name, $filter_chapter, $filter_barangay, $filter_group); ?>">
                                        <i class="fas fa-chevron-right"></i>
                                    </a>
                                </li>
                                <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="<?php echo buildPendUrl($total_pages, $limit, $search, $filter_name, $filter_chapter, $filter_barangay, $filter_group); ?>">
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

<!-- View Details Modal -->
<div class="modal fade" id="viewDetailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white py-2">
                <h5 class="modal-title"><i class="fas fa-file-alt me-2"></i>Application for Membership - Pending Approval</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4" id="detailsContent">
                <div class="text-center p-5">
                    <div class="spinner-border text-dark" role="status"><span class="visually-hidden">Loading...</span></div>
                </div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- ============================================================
     REJECTION MODAL (BLACK THEME)
     ============================================================ -->
<div class="modal fade reject-modal" id="rejectModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" id="rejectForm">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="action" value="reject">
                <input type="hidden" name="pending_id" id="rejectPendingId" value="0">
                <input type="hidden" name="page" value="<?php echo $page; ?>">
                <input type="hidden" name="limit" value="<?php echo $limit; ?>">

                <div class="modal-header py-3">
                    <h5 class="modal-title"><i class="fas fa-user-times me-2"></i>Reject Application</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">

                    <!-- Applicant summary -->
                    <div class="applicant-summary">
                        <div class="row-line"><span class="lbl">Name:</span><span class="val" id="rName">—</span></div>
                        <div class="row-line"><span class="lbl">Username:</span><span class="val" id="rUsername">—</span></div>
                        <div class="row-line"><span class="lbl">Email:</span><span class="val" id="rEmail">—</span></div>
                        <div class="row-line"><span class="lbl">Contact:</span><span class="val" id="rContact">—</span></div>
                        <div class="row-line"><span class="lbl">Member #:</span><span class="val" id="rMemberCode">—</span></div>
                    </div>

                    <!-- Reason picker -->
                    <label class="form-label fw-semibold mb-1" style="font-size:.9rem;">
                        Select Rejection Reason <span class="text-danger">*</span>
                    </label>
                    <div id="reasonPicker">
                        <?php
                        $grouped = RejectionReasons::grouped();
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

                    <!-- Custom reason -->
                    <div class="custom-reason-box" id="customReasonBox">
                        <label class="form-label fw-semibold" style="font-size:.85rem;">
                            <i class="fas fa-pencil-alt me-1"></i>Custom reason
                        </label>
                        <textarea class="form-control" name="custom_reason" id="customReason"
                                  maxlength="500"
                                  placeholder="Explain briefly why the application is being rejected…"></textarea>
                        <div class="char-counter" id="charCounter">0 / 500</div>
                    </div>

                    <!-- Preview -->
                    <div class="preview-panel" id="previewPanel">
                        <div class="preview-label"><i class="fas fa-envelope me-1"></i>Applicant will receive:</div>
                        <div class="preview-subject" id="pvSubject">Subject: Your membership application status — NHGL</div>
                        <div class="preview-body" id="pvBody">Choose a reason above to see the message preview…</div>
                    </div>

                    <!-- Channels -->
                    <div class="channels-row">
                        <div class="ch"><i class="fas fa-check-circle"></i> In-app notification</div>
                        <div class="ch"><i class="fas fa-check-circle"></i> Email</div>
                        <div class="ch"><i class="fas fa-check-circle"></i> SMS</div>
                    </div>

                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn-reject-confirm" id="btnRejectConfirm" disabled>
                        <i class="fas fa-paper-plane me-1"></i>Reject &amp; Notify Applicant
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
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

/* ---------- Auto-flip dropdowns ---------- */
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

/* ---------- View Details ---------- */
function viewDetails(id) {
    const modal = new bootstrap.Modal(document.getElementById('viewDetailsModal'));
    document.getElementById('detailsContent').innerHTML = `
        <div class="text-center p-5">
            <div class="spinner-border text-dark" role="status"><span class="visually-hidden">Loading...</span></div>
        </div>
    `;
    modal.show();

    fetch(`get_pending_details.php?id=${id}`)
        .then(r => { if (!r.ok) throw new Error('Network response was not ok'); return r.json(); })
        .then(data => {
            const fmt = d => (d && d !== '0000-00-00')
                ? new Date(d).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' })
                : 'N/A';
            const birthDate      = fmt(data.birth_date);
            const screeningDate  = fmt(data.screening_date);
            const dateRegistered = fmt(data.date_registered);
            const dateJoined     = fmt(data.date_joined);
            const requestedAt    = data.requested_at
                ? new Date(data.requested_at).toLocaleString('en-US', { year:'numeric', month:'long', day:'numeric', hour:'2-digit', minute:'2-digit' })
                : 'N/A';

            const fatherName = [data.father_fname, data.father_mname, data.father_lname].filter(Boolean).join(' ');
            const motherName = [data.mother_fname, data.mother_mname, data.mother_lname].filter(Boolean).join(' ');
            const spouseName = [data.spouse_fname, data.spouse_mname, data.spouse_lname].filter(Boolean).join(' ');

            const child1Name = [data.child1_fname, data.child1_mname, data.child1_lname].filter(Boolean).join(' ');
            const child2Name = [data.child2_fname, data.child2_mname, data.child2_lname].filter(Boolean).join(' ');
            const child3Name = [data.child3_fname, data.child3_mname, data.child3_lname].filter(Boolean).join(' ');
            const child4Name = [data.child4_fname, data.child4_mname, data.child4_lname].filter(Boolean).join(' ');

            const fullAddress = [data.street, data.barangay ? 'Brgy. ' + data.barangay : '', data.city, data.province].filter(Boolean).join(', ');
            const photoUrl = data.registration_photo ? '../' + data.registration_photo : null;
            const medUrl   = data.medical_certificate_file ? '../' + data.medical_certificate_file : null;
            const birthUrl = data.birth_certificate_file ? '../' + data.birth_certificate_file : null;

            let html = `
                <div class="application-form">
                    <div class="logo-section">
                        <img src="../assets/images/harana-logo.png" alt="Harana Logo" onerror="this.style.display='none'; this.parentNode.querySelector('.logo-placeholder').style.display='flex';">
                        <div class="logo-placeholder" style="display:none;"><i class="fas fa-hand-holding-heart fa-3x"></i></div>
                        <div class="text-content">
                            <h2>NAGKAISANG HIRANISTA</h2>
                            <h5>SA GINTONG LUZON, PHILS. INC. (NHGL, INC.)</h5>
                            <p class="small">(Formerly Nagkaisang Hiranista Sa Gintong Luzon, Inc.)</p>
                            <p class="small">(Sec. REG No. CN 700172104)</p>
                            <p class="small">MF 2024<br>Bryg. Singalat, Palayan City<br>Province of Nueva Ecija<br>Tel. No. (044)940-6708</p>
                        </div>
                    </div>

                    <h4 class="form-title">APPLICATION FOR MEMBERSHIP</h4>

                    <div class="text-center mb-3">
                        <span class="badge bg-warning text-dark px-3 py-2"><i class="fas fa-clock me-2"></i>PENDING APPROVAL</span>
                        <br><small class="text-muted">Requested on: ${requestedAt}</small>
                    </div>

                    <div class="documents-section">
                        <h4>Documents Attached:</h4>
                        <div class="checkbox-group">
                            <div class="checkbox-item">
                                <input type="checkbox" ${data.medical_certificate == 1 ? 'checked' : ''} disabled>
                                <label>Medical Certificate</label>
                                ${medUrl
                                    ? '<a href="' + medUrl + '" target="_blank" class="btn btn-sm btn-outline-primary ms-2"><i class="fas fa-eye"></i> View File</a>'
                                    : '<span class="text-muted small ms-2">(no file uploaded)</span>'}
                            </div>
                            <div class="checkbox-item">
                                <input type="checkbox" ${data.birth_certificate == 1 ? 'checked' : ''} disabled>
                                <label>Birth Certificate</label>
                                ${birthUrl
                                    ? '<a href="' + birthUrl + '" target="_blank" class="btn btn-sm btn-outline-primary ms-2"><i class="fas fa-eye"></i> View File</a>'
                                    : '<span class="text-muted small ms-2">(no file uploaded)</span>'}
                            </div>
                        </div>
                    </div>`;

            if (photoUrl) {
                html += `
                    <div class="photo-section text-center">
                        <img src="${photoUrl}" alt="Applicant Photo" class="pending-photo" onerror="this.style.display='none'">
                        <div><small class="text-muted">Submitted ID Picture (2x2)</small></div>
                    </div>`;
            }

            html += `
                    <div class="section-title">I. PERSONAL INFORMATION</div>
                    <table class="info-table">
                        <tr>
                            <td class="label">Member Code:</td><td>${data.member_code || 'Auto-generated'}</td>
                            <td class="label">Date Joined:</td><td>${dateJoined}</td>
                            <td class="label">Monthly Contribution:</td><td>₱${parseFloat(data.monthly_contribution || 0).toFixed(2)}</td>
                        </tr>
                        <tr>
                            <td class="label">Last Name:</td><td>${data.last_name || ''}</td>
                            <td class="label">First Name:</td><td>${data.first_name || ''}</td>
                            <td class="label">Middle Name:</td><td>${data.middle_name || ''}</td>
                        </tr>
                        <tr>
                            <td class="label">Date of Birth:</td><td>${birthDate}</td>
                            <td class="label">Place of Birth:</td><td>${data.place_of_birth || ''}</td>
                            <td class="label">Age:</td><td>${data.age || ''}</td>
                        </tr>
                        <tr>
                            <td class="label">Gender:</td><td>${data.gender || ''}</td>
                            <td class="label">Civil Status:</td><td>${data.civil_status || ''}</td>
                            <td class="label">Religion:</td><td>${data.religion || ''}</td>
                        </tr>
                    </table>

                    <div class="section-title">II. ADDRESS INFORMATION</div>
                    <table class="info-table">
                        <tr><td class="label">Present Address:</td><td colspan="5">${data.present_address || fullAddress}</td></tr>
                        <tr><td class="label">Permanent Address:</td><td colspan="5">${data.permanent_address || fullAddress}</td></tr>
                    </table>

                    <div class="section-title">III. CONTACT INFORMATION</div>
                    <table class="info-table">
                        <tr>
                            <td class="label">Contact Number:</td><td>${data.contact_number || ''}</td>
                            <td class="label">Alternate Number:</td><td colspan="3">${data.alternate_number || ''}</td>
                        </tr>
                        <tr><td class="label">Email Address:</td><td colspan="5">${data.email || ''}</td></tr>
                    </table>

                    <div class="section-title">IV. FAMILY BACKGROUND</div>
                    <table class="info-table">
                        <tr>
                            <td class="label">Father's Name:</td><td colspan="2">${fatherName}</td>
                            <td class="label">Mother's Name:</td><td colspan="2">${motherName}</td>
                        </tr>
                        <tr>
                            <td class="label">Spouse's Name:</td><td colspan="3">${spouseName}</td>
                            <td class="label">Age:</td><td>${data.spouse_age || ''}</td>
                        </tr>
                    </table>

                    <div class="section-title">V. CHILDREN</div>
                    <table class="info-table">
                        <tr><td class="label">1.</td><td colspan="3">${child1Name}</td><td class="label">Age:</td><td>${data.child1_age || ''}</td></tr>
                        <tr><td class="label">2.</td><td colspan="3">${child2Name}</td><td class="label">Age:</td><td>${data.child2_age || ''}</td></tr>
                        <tr><td class="label">3.</td><td colspan="3">${child3Name}</td><td class="label">Age:</td><td>${data.child3_age || ''}</td></tr>
                        <tr><td class="label">4.</td><td colspan="3">${child4Name}</td><td class="label">Age:</td><td>${data.child4_age || ''}</td></tr>
                    </table>

                    <div class="section-title">VI. CHARACTER REFERENCES</div>
                    <table class="info-table">
                        <tr><td class="label">1.</td><td>${data.ref1_name || ''}</td><td class="label">Contact:</td><td colspan="3">${data.ref1_contact || ''}</td></tr>
                        <tr><td class="label">2.</td><td>${data.ref2_name || ''}</td><td class="label">Contact:</td><td colspan="3">${data.ref2_contact || ''}</td></tr>
                    </table>

                    <div class="section-title">VII. BENEFICIARY INFORMATION</div>
                    <table class="info-table">
                        <tr><td class="label">Name:</td><td colspan="5">${data.beneficiary_name || ''}</td></tr>
                        <tr><td class="label">Address:</td><td colspan="5">${data.beneficiary_address || ''}</td></tr>
                        <tr>
                            <td class="label">Relationship:</td><td>${data.beneficiary_relation || ''}</td>
                            <td class="label">Age:</td><td>${data.beneficiary_age || ''}</td>
                            <td class="label">Contact:</td><td>${data.beneficiary_contact || ''}</td>
                        </tr>
                    </table>

                    <div class="section-title">VIII. CHAPTER INFORMATION</div>
                    <table class="info-table">
                        <tr><td class="label">Chapter:</td><td>${data.chapter || ''}</td><td class="label">Group Name:</td><td colspan="3">${data.group_name || ''}</td></tr>
                        <tr><td class="label">Leader:</td><td>${data.leader || ''}</td><td class="label">Coordinator:</td><td colspan="3">${data.coordinator || ''}</td></tr>
                        <tr><td class="label">Chairman:</td><td>${data.chairman || ''}</td><td class="label">Screening Officer:</td><td colspan="3">${data.screening_officer || ''}</td></tr>
                        <tr><td class="label">Screening Date:</td><td>${screeningDate}</td><td class="label">Approved By:</td><td colspan="3">${data.approved_by || ''}</td></tr>
                        <tr><td class="label">Date Registered:</td><td>${dateRegistered}</td><td class="label">Status:</td><td colspan="3"><span class="badge bg-warning">Pending</span></td></tr>
                    </table>

                    <div class="section-title">IX. ACCOUNT INFORMATION</div>
                    <table class="info-table">
                        <tr><td class="label">Username:</td><td colspan="5">${data.username || 'Not set'}</td></tr>
                    </table>
                </div>`;

            document.getElementById('detailsContent').innerHTML = html;
        })
        .catch(err => {
            document.getElementById('detailsContent').innerHTML = `
                <div class="alert alert-danger m-3">
                    <i class="fas fa-exclamation-circle me-2"></i>Error loading details: ${err.message}
                </div>`;
        });
}

/* ============================================================
   REJECTION MODAL LOGIC
   ============================================================ */
const REJECT_REASONS = <?php
echo json_encode(
    array_map(
        fn($row) => [
            'label'   => $row['label'],
            'message' => $row['message'],
        ],
        RejectionReasons::all()
    ),
    JSON_HEX_APOS | JSON_HEX_QUOT
);
?>;

let rejectModalInstance = null;

function openRejectModal(user) {
    const fullName = [user.first_name, user.middle_name, user.last_name].filter(Boolean).join(' ');
    document.getElementById('rName').textContent       = fullName || '—';
    document.getElementById('rUsername').textContent   = user.username       || '—';
    document.getElementById('rEmail').textContent      = user.email          || '—';
    document.getElementById('rContact').textContent    = user.contact_number || '—';
    document.getElementById('rMemberCode').textContent = user.member_code    || '—';

    document.getElementById('rejectPendingId').value = user.id;

    document.querySelectorAll('#reasonPicker .reason-option').forEach(opt => {
        opt.classList.remove('selected');
        const radio = opt.querySelector('input[type="radio"]');
        radio.checked = false;
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
        const radio = this.querySelector('input[type="radio"]');
        radio.checked = true;

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

    const reason = REJECT_REASONS[code] || { label: '', message: '' };
    let message = reason.message;
    let extra = '';

    if (code === 'other') {
        const custom = document.getElementById('customReason').value.trim();
        if (!custom) {
            confirmBtn.disabled = true;
            bodyEl.textContent = 'Type a custom reason to see the message preview…';
            return;
        }
        message = custom;
        extra = '\n\n(Admin may add additional context in the email body.)';
    }

    confirmBtn.disabled = false;

    bodyEl.textContent =
        `Dear [Applicant Name],\n\n` +
        `Thank you for your interest in joining NHGL, Inc.\n\n` +
        `After reviewing your application, we regret to inform you that we are unable to approve it at this time.\n\n` +
        `Reason: ${reason.label}\n` +
        `${message}${extra}\n\n` +
        `You may check your application status by visiting:\n` +
        `application_status.php`;
}
</script>
</body>
</html>