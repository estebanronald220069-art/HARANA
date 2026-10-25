<?php
// admin/payments.php
// Refactored for strict data integrity.

require_once '../includes/config.php';
require_once '../includes/db_connection.php';
require_once '../includes/security.php';
require_once '../includes/auth.php';
require_once '../includes/user_functions.php';

// Load GCash settings helper
if (!function_exists('getAppSetting')) {
    function getAppSetting($db, $key, $default = null) {
        try {
            $row = $db->getSingle(
                "SELECT setting_value FROM app_settings WHERE setting_key = ?",
                [$key], 's'
            );
            return $row['setting_value'] ?? $default;
        } catch (Throwable $e) {
            return $default;
        }
    }
}

$auth->requireLogin();
$current_user = $auth->getCurrentUser();
$db = Database::getInstance();

/* ============================================================
   PENDING USERS BADGE
   ============================================================ */
$pending_count = 0;
if ($current_user['role'] === 'admin') {
    $pending_count = $db->getSingle("SELECT COUNT(*) as cnt FROM pending_users WHERE status = 'pending'")['cnt'] ?? 0;
}

$success_toast = '';
$error_toast   = '';

/* ============================================================
   FLASH
   ============================================================ */
if (isset($_GET['success'])) {
    $map = [
        'added'    => 'Payment recorded successfully (pending confirmation).',
        'updated'  => 'Payment updated successfully.',
        'confirmed'=> 'Payment confirmed successfully.',
        'voided'   => 'Payment voided.',
    ];
    $success_toast = $map[$_GET['success']] ?? '';
}

/* ============================================================
   PAGINATION + FILTERS
   ============================================================ */
$allowed_limits = [10, 15, 25, 50, 100];
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = isset($_GET['limit']) && in_array((int)$_GET['limit'], $allowed_limits, true)
            ? (int)$_GET['limit'] : 15;
$offset = ($page - 1) * $limit;

$search        = isset($_GET['search'])      ? Security::sanitize($_GET['search'])      : '';
$status_filter = isset($_GET['status'])      ? Security::sanitize($_GET['status'])      : '';
$method_filter = isset($_GET['method'])      ? Security::sanitize($_GET['method'])      : '';
$date_from     = isset($_GET['date_from'])   ? Security::sanitize($_GET['date_from'])   : '';
$date_to       = isset($_GET['date_to'])     ? Security::sanitize($_GET['date_to'])     : '';

$conditions = [];
$params     = [];
$types      = '';

if ($search !== '') {
    $conditions[] = "(m.first_name LIKE ? OR m.last_name LIKE ? OR p.receipt_number LIKE ? OR m.member_code LIKE ?)";
    $like = "%$search%";
    $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
    $types .= 'ssss';
}
if ($status_filter !== '') {
    $conditions[] = "p.payment_status = ?";
    $params[] = $status_filter;
    $types .= 's';
}
if ($method_filter !== '') {
    $conditions[] = "p.payment_method = ?";
    $params[] = $method_filter;
    $types .= 's';
}
if ($date_from !== '') {
    $conditions[] = "p.payment_date >= ?";
    $params[] = $date_from;
    $types .= 's';
}
if ($date_to !== '') {
    $conditions[] = "p.payment_date <= ?";
    $params[] = $date_to;
    $types .= 's';
}

$where_sql = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

// FIX: Changed p.member_id to p.member_code
$count_sql = "SELECT COUNT(*) as total FROM payments p
              JOIN members m ON p.member_code = m.member_code
              $where_sql";
$total_records = (int)($db->getSingle($count_sql, $params, $types)['total'] ?? 0);
$total_pages   = max(1, (int)ceil($total_records / $limit));
if ($page > $total_pages) $page = $total_pages;

// FIX: Changed p.member_id to p.member_code
$sql = "SELECT p.*,
               m.first_name, m.last_name, m.member_code,
               u.full_name as confirmed_by_name
        FROM payments p
        JOIN members m ON p.member_code = m.member_code
        LEFT JOIN users u ON p.confirmed_by = u.user_id
        $where_sql
        ORDER BY p.created_at DESC
        LIMIT ? OFFSET ?";
$payments = $db->getAll($sql, array_merge($params, [$limit, $offset]), $types . 'ii');

$members = $db->getAll("SELECT member_code, first_name, last_name FROM members WHERE status = 'active' ORDER BY first_name");

/* ============================================================
   POST HANDLERS
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error_toast = 'Invalid security token.';
    } else {
        $action = $_POST['action'] ?? '';

        /* ---------- ADD ---------- */
        if ($action === 'add') {
            $member_code     = Security::sanitize($_POST['member_code'] ?? '');
            $amount          = floatval($_POST['amount'] ?? 0);
            $payment_date    = $_POST['payment_date'] ?? date('Y-m-d');
            $payment_method  = Security::sanitize($_POST['payment_method'] ?? 'cash');
            $gcash_reference = Security::sanitize($_POST['gcash_reference'] ?? '');
            $notes           = Security::sanitize($_POST['notes'] ?? '');

            $period_month = $_POST['period_month'] ?? '';
            if (!preg_match('/^\d{4}-\d{2}$/', $period_month)) {
                $period_month = date('Y-m');
            }

            if ($member_code === '' || $amount <= 0) {
                $error_toast = 'Please select a member and enter a valid amount.';
            } else {
                $member_check = $db->getSingle("SELECT member_code FROM members WHERE member_code = ?", [$member_code], 's');
                if (!$member_check) {
                    $error_toast = 'Selected member does not exist.';
                } else {
                    $payment_uuid = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
                        mt_rand(0, 0xffff),
                        mt_rand(0, 0x0fff) | 0x4000,
                        mt_rand(0, 0x3fff) | 0x8000,
                        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
                    );
                    require_once __DIR__ . '/../includes/receipts.php';
                    $receipt_number = generateReceiptNumber($db);
                    
                    // Strict numeric cast for identity
                    $member_id_int = (int)$member_code;

                    $insert_sql = "INSERT INTO payments
                        (payment_uuid, member_id, member_code, payment_date, period_month, amount, due_date, payment_method, gcash_reference, payment_status, receipt_number, notes, ip_address)
                        VALUES (?, ?, ?, ?, ?, ?, NULL, ?, ?, 'pending', ?, ?, ?)";
                    
                    // FIX: Fixed the types string to match the 11 parameters
                    $insert_params = [$payment_uuid, $member_id_int, $member_code, $payment_date, $period_month, $amount, $payment_method, $gcash_reference, $receipt_number, $notes, Security::getClientIP()];
                    $insert_types = 'sissssdssss'; 

                    if ($db->execute($insert_sql, $insert_params, $insert_types)) {
                        Security::logEvent('PAYMENT_ADD', "Added payment for member $member_code, amount $amount, period $period_month");
                        header('Location: payments.php?success=added' . "&page=$page&limit=$limit");
                        exit();
                    }
                    $error_toast = 'Failed to record payment.';
                }
            }
        }

        /* ---------- EDIT ---------- */
        elseif ($action === 'edit') {
            $payment_id = (int)($_POST['payment_id'] ?? 0);
            $payment = $db->getSingle("SELECT payment_status FROM payments WHERE payment_id = ?", [$payment_id], 'i');
            if (!$payment || $payment['payment_status'] !== 'pending') {
                $error_toast = 'Only pending payments can be edited.';
            } else {
                $member_code     = Security::sanitize($_POST['member_code'] ?? '');
                $amount          = floatval($_POST['amount'] ?? 0);
                $payment_date    = $_POST['payment_date'] ?? date('Y-m-d');
                $payment_method  = Security::sanitize($_POST['payment_method'] ?? 'cash');
                $gcash_reference = Security::sanitize($_POST['gcash_reference'] ?? '');
                $notes           = Security::sanitize($_POST['notes'] ?? '');

                $period_month = $_POST['period_month'] ?? '';
                if (!preg_match('/^\d{4}-\d{2}$/', $period_month)) {
                    $period_month = date('Y-m');
                }

                if ($member_code === '' || $amount <= 0) {
                    $error_toast = 'Please select a member and enter a valid amount.';
                } else {
                    $member_id_int = (int)$member_code;

                    // FIX: Added member_code to the update statement
                    $update_sql = "UPDATE payments SET
                        member_id=?, member_code=?, amount=?, payment_date=?, period_month=?, due_date=NULL,
                        payment_method=?, gcash_reference=?, notes=?
                        WHERE payment_id=?";
                    $update_params = [$member_id_int, $member_code, $amount, $payment_date, $period_month, $payment_method, $gcash_reference, $notes, $payment_id];
                    $update_types = 'isssdssssi';

                    if ($db->execute($update_sql, $update_params, $update_types) !== false) {
                        Security::logEvent('PAYMENT_EDIT', "Edited payment ID $payment_id");
                        header('Location: payments.php?success=updated' . "&page=$page&limit=$limit");
                        exit();
                    }
                    $error_toast = 'Failed to update payment.';
                }
            }
        }

        /* ---------- CONFIRM ---------- */
        elseif ($action === 'confirm') {
            $payment_id = (int)($_POST['payment_id'] ?? 0);
            $payment = $db->getSingle("SELECT * FROM payments WHERE payment_id = ? AND payment_status = 'pending'", [$payment_id], 'i');
            if (!$payment) {
                $error_toast = 'Payment not found or already confirmed.';
            } else {
                $period_month = $_POST['period_month'] ?? '';
                if (!preg_match('/^\d{4}-\d{2}$/', $period_month)) {
                    $period_month = !empty($payment['period_month'])
                        ? $payment['period_month']
                        : date('Y-m', strtotime($payment['payment_date']));
                }

                if ($db->execute(
                    "UPDATE payments SET payment_status='confirmed', period_month=?, confirmed_by=?, confirmed_date=NOW() WHERE payment_id=?",
                    [$period_month, $current_user['user_id'], $payment_id], 'sii'
                )) {

                    $member_details = $db->getSingle(
                        "SELECT user_id, first_name, last_name, member_code FROM members WHERE member_code = ?",
                        [$payment['member_code']], 's'
                    );

                    // FIX: Recalculate balances from scratch for this specific member to prevent stale cache
                    $live_paid = (float)($db->getSingle(
                        "SELECT COALESCE(SUM(amount), 0) as total FROM payments WHERE member_code = ? AND payment_status = 'confirmed'",
                        [$payment['member_code']], 's'
                    )['total'] ?? 0);

                    $total_due = (float)($db->getSingle(
                        "SELECT (PERIOD_DIFF(DATE_FORMAT(CURDATE(), '%Y%m'), DATE_FORMAT(date_joined, '%Y%m')) + 1) * monthly_contribution AS total FROM members WHERE member_code = ?",
                        [$payment['member_code']], 's'
                    )['total'] ?? 0);

                    $current_balance = $total_due - $live_paid;

                    $balance_exists = $db->getSingle("SELECT balance_id FROM member_balances WHERE member_code = ?", [$payment['member_code']], 's');
                    
                    if ($balance_exists) {
                        $db->execute(
                            "UPDATE member_balances SET total_paid = ?, total_due = ?, current_balance = ?, last_payment_date = ? WHERE member_code = ?",
                            [$live_paid, $total_due, $current_balance, $payment['payment_date'], $payment['member_code']],
                            'ddsss'
                        );
                    } else {
                        $db->execute(
                            "INSERT INTO member_balances (member_code, total_paid, total_due, current_balance, last_payment_date) VALUES (?, ?, ?, ?, ?)",
                            [$payment['member_code'], $live_paid, $total_due, $current_balance, $payment['payment_date']],
                            'sddds'
                        );
                    }

                    if ($member_details && $member_details['user_id'] && function_exists('createNotification')) {
                        createNotification(
                            $db,
                            $member_details['user_id'],
                            "Payment Confirmed",
                            "Your payment of ₱" . number_format($payment['amount'], 2) .
                            " has been confirmed. Receipt #: " . $payment['receipt_number'],
                            'payment',
                            '../user/payments.php'
                        );
                    }
                    if ($member_details && $member_details['user_id'] && function_exists('deletePaidReminders')) {
                        deletePaidReminders($db, $member_details['user_id']);
                    }

                    Security::logEvent('PAYMENT_CONFIRM', "Confirmed payment ID $payment_id for period $period_month");
                    header('Location: payments.php?success=confirmed' . "&page=$page&limit=$limit");
                    exit();
                }
                $error_toast = 'Failed to confirm payment.';
            }
        }

        /* ---------- VOID ---------- */
        elseif ($action === 'void') {
            $payment_id = (int)($_POST['payment_id'] ?? 0);
            $payment = $db->getSingle("SELECT payment_status FROM payments WHERE payment_id = ?", [$payment_id], 'i');
            if (!$payment || $payment['payment_status'] !== 'pending') {
                $error_toast = 'Only pending payments can be voided.';
            } else {
                if ($db->execute("UPDATE payments SET payment_status='failed' WHERE payment_id=?", [$payment_id], 'i')) {
                    Security::logEvent('PAYMENT_VOID', "Voided payment ID $payment_id");
                    header('Location: payments.php?success=voided' . "&page=$page&limit=$limit");
                    exit();
                }
                $error_toast = 'Failed to void payment.';
            }
        }
    }
}

$csrf_token = Security::generateCSRFToken();

function buildPayUrl($page, $limit, $search, $status, $method, $date_from, $date_to) {
    $q = ['page' => $page, 'limit' => $limit];
    if ($search       !== '') $q['search']      = $search;
    if ($status       !== '') $q['status']      = $status;
    if ($method       !== '') $q['method']      = $method;
    if ($date_from    !== '') $q['date_from']   = $date_from;
    if ($date_to      !== '') $q['date_to']     = $date_to;
    return '?' . http_build_query($q);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payments - <?php echo APP_NAME; ?></title>
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

    .btn-success {
        background: #1c1c1d;
        border-color: #1c1c1d;
    }
    .btn-success:hover,
    .btn-success:focus,
    .btn-success:active {
        background: #2c2c2d !important;
        border-color: #2c2c2d !important;
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
    .filter-actions .btn-primary {
        background: #1c1c1d;
        border: none;
    }
    .filter-actions .btn-primary:hover {
        background: #2c2c2d;
    }
    .filter-actions .btn-secondary {
        background: #6c757d;
        border: none;
    }
    .filter-actions .btn-secondary:hover {
        background: #5a6268;
    }

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

    .status-badge {
        display: inline-block;
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.3px;
    }
    .status-active   { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
    .status-inactive { background: #fff3cd; color: #856404; border: 1px solid #ffeeba; }
    .status-deceased { background: #e2e3e5; color: #383d41; border: 1px solid #d6d8db; }

    .amount-cell { font-weight: 600; color: #155724; }
    .receipt-code {
        background: #e9ecef; color: #495057;
        padding: 4px 8px; border-radius: 6px;
        font-family: monospace; font-size: 11px; font-weight: 600;
    }
    .method-badge {
        display: inline-block; padding: 3px 10px; border-radius: 12px;
        font-size: .7rem; font-weight: 600;
        background: #f1f3f5; color: #495057;
    }
    .method-gcash  { background: #e0f2fe; color: #075985; }
    .method-cash   { background: #dcfce7; color: #166534; }
    .method-bank   { background: #fef3c7; color: #92400e; }

    .modal-header.bg-primary,
    .modal-header.bg-dark {
        background: #1c1c1d !important;
        color: #fff !important;
    }

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
            <a href="payments.php" class="list-group-item active"><i class="fas fa-credit-card"></i><span>Payments</span></a>
           <!-- <a href="gcash_settings.php" class="list-group-item"><i class="fas fa-qrcode"></i><span>GCash Settings</span></a>-->
             <a href="remittances.php" class="list-group-item"><i class="fas fa-hand-holding-usd"></i><span>Remittances</span></a>
            <a href="reports.php" class="list-group-item"><i class="fas fa-chart-bar"></i><span>Reports</span></a>
            <a href="announcements.php" class="list-group-item"><i class="fas fa-bullhorn"></i><span>Send Announcement</span></a>
            <?php if ($current_user['role'] === 'admin'): ?>
            <a href="pending_users.php" class="list-group-item">
                <i class="fas fa-user-clock"></i><span>Pending</span>
                <?php if ($pending_count > 0): ?>
                    <span class="badge bg-danger badge-count"><?php echo $pending_count; ?></span>
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
                <span class="navbar-brand"><i class="fas fa-credit-card me-2"></i>Payments Management</span>
            </div>
            <div class="navbar-right">
                <a href="gcash_settings.php" class="btn btn-sm btn-outline-secondary">
                    <i class="fas fa-qrcode me-1"></i>GCash Settings
                </a>
                <span class="text-muted small">
                    <i class="fas fa-calendar-alt me-1"></i><?php echo date('F j, Y'); ?>
                </span>
                <span class="small"><i class="fas fa-user-circle me-2"></i><?php echo htmlspecialchars($current_user['full_name'] ?? $current_user['username']); ?></span>
            </div>
        </nav>

        <div class="container-fluid p-3">

            <!-- Toasts -->
            <div class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 999999 !important; top: 70px !important;">
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

            <!-- Action toolbar -->
            <div class="action-toolbar">
                <div class="search-group">
                    <form method="GET" class="d-flex w-100 gap-2">
                        <input type="hidden" name="limit" value="<?php echo $limit; ?>">
                        <input type="text" name="search" class="search-input"
                               placeholder="Search member, receipt #, or member code..."
                               value="<?php echo htmlspecialchars($search); ?>">
                        <button type="submit" class="btn-search"><i class="fas fa-search"></i> Search</button>
                    </form>
                </div>
                <div class="action-buttons-group">
                    <a href="payments.php" class="btn btn-outline-secondary">
                        <i class="fas fa-undo me-1"></i>Reset
                    </a>
                    <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addPaymentModal">
                        <i class="fas fa-plus me-1"></i>Record Payment
                    </button>
                </div>
            </div>

            <!-- Filter bar -->
            <div class="filter-bar">
                <form method="GET" class="filter-row">
                    <div class="filter-item">
                        <label>Status</label>
                        <select name="status" class="form-select">
                            <option value="">All Status</option>
                            <option value="pending"   <?php echo $status_filter === 'pending'   ? 'selected' : ''; ?>>Pending</option>
                            <option value="confirmed" <?php echo $status_filter === 'confirmed' ? 'selected' : ''; ?>>Confirmed</option>
                            <option value="failed"    <?php echo $status_filter === 'failed'    ? 'selected' : ''; ?>>Failed</option>
                        </select>
                    </div>
                    <div class="filter-item">
                        <label>Method</label>
                        <select name="method" class="form-select">
                            <option value="">All Methods</option>
                            <option value="cash"          <?php echo $method_filter === 'cash'          ? 'selected' : ''; ?>>Cash</option>
                            <option value="gcash"         <?php echo $method_filter === 'gcash'         ? 'selected' : ''; ?>>GCash</option>
                            <option value="bank_transfer" <?php echo $method_filter === 'bank_transfer' ? 'selected' : ''; ?>>Bank Transfer</option>
                        </select>
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
                        <a href="payments.php" class="btn btn-secondary"><i class="fas fa-undo me-1"></i> Reset</a>
                    </div>
                    <input type="hidden" name="search" value="<?php echo htmlspecialchars($search); ?>">
                </form>
            </div>

            <!-- Table -->
            <div class="card shadow-sm">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0 align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Receipt #</th>
                                    <th>Member</th>
                                    <th>Date</th>
                                    <th>Period</th>
                                    <th>Amount</th>
                                    <th>Method</th>
                                    <th>Status</th>
                                    <th>Confirmed By</th>
                                    <th style="width:80px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (empty($payments)): ?>
                                <tr><td colspan="9" class="text-center py-5">
                                    <i class="fas fa-credit-card fa-3x text-muted mb-3"></i>
                                    <p class="text-muted mb-0">No payments found.</p>
                                </td></tr>
                            <?php else: foreach ($payments as $p):
                                $methodClass = 'method-badge';
                                if ($p['payment_method'] === 'gcash')          $methodClass .= ' method-gcash';
                                elseif ($p['payment_method'] === 'cash')       $methodClass .= ' method-cash';
                                elseif ($p['payment_method'] === 'bank_transfer') $methodClass .= ' method-bank';

                                // NEW: display period — prefer period_month, fall back to payment_date month
                                $display_period = !empty($p['period_month'])
                                    ? $p['period_month']
                                    : date('Y-m', strtotime($p['payment_date']));
                            ?>
                                <tr>
                                    <td><span class="receipt-code"><?php echo htmlspecialchars($p['receipt_number']); ?></span></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($p['first_name'] . ' ' . $p['last_name']); ?></strong>
                                        <br><small class="text-muted"><?php echo htmlspecialchars($p['member_code']); ?></small>
                                    </td>
                                    <td><small><?php echo date('M d, Y', strtotime($p['payment_date'])); ?></small></td>
                                    <td><small><?php echo date('M Y', strtotime($display_period . '-01')); ?></small></td>
                                    <td class="amount-cell">₱<?php echo number_format($p['amount'], 2); ?></td>
                                    <td><span class="<?php echo $methodClass; ?>"><?php echo ucfirst(str_replace('_', ' ', $p['payment_method'])); ?></span></td>
                                    <td>
                                        <?php if ($p['payment_status'] === 'confirmed'): ?>
                                            <span class="status-badge status-active">Confirmed</span>
                                        <?php elseif ($p['payment_status'] === 'pending'): ?>
                                            <span class="status-badge status-inactive">Pending</span>
                                        <?php else: ?>
                                            <span class="status-badge status-deceased">Failed</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><small><?php echo $p['confirmed_by_name'] ? htmlspecialchars($p['confirmed_by_name']) : '—'; ?></small></td>
                                    <td>
                                        <div class="dropdown">
                                            <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="dropdown" aria-expanded="false">
                                                <i class="fas fa-ellipsis-v"></i>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-end">
                                                <?php if ($p['payment_status'] === 'pending'): ?>

    <?php if (!empty($p['proof_of_payment'])): ?>
    <li>
        <a class="dropdown-item" href="../<?php echo htmlspecialchars($p['proof_of_payment']); ?>"
           target="_blank" rel="noopener">
            <i class="fas fa-image me-2" style="color:#0070ba;"></i>View Payment Proof
        </a>
    </li>
    <?php endif; ?>

    <li>
        <a class="dropdown-item" href="#"
           data-id="<?php echo $p['payment_id']; ?>"
           data-proof="<?php echo htmlspecialchars($p['proof_of_payment'] ?? ''); ?>"
           data-period="<?php echo htmlspecialchars($p['period_month'] ?? ''); ?>"
           data-payment-date="<?php echo htmlspecialchars($p['payment_date']); ?>"
           data-bs-toggle="modal" data-bs-target="#confirmPaymentModal"
           onclick="setConfirmId(this);">
            <i class="fas fa-check text-success me-2"></i>Confirm Payment
        </a>
    </li>
                                                    <li>
                                                        <a class="dropdown-item edit-trigger" href="#"
                                                           data-id="<?php echo $p['payment_id']; ?>"
                                                           data-member="<?php echo $p['member_code']; ?>"
                                                           data-amount="<?php echo $p['amount']; ?>"
                                                           data-date="<?php echo $p['payment_date']; ?>"
                                                           data-period="<?php echo htmlspecialchars($p['period_month'] ?? ''); ?>"
                                                           data-method="<?php echo $p['payment_method']; ?>"
                                                           data-gcash="<?php echo htmlspecialchars($p['gcash_reference'], ENT_QUOTES); ?>"
                                                           data-notes="<?php echo htmlspecialchars($p['notes'], ENT_QUOTES); ?>"
                                                           data-bs-toggle="modal" data-bs-target="#editPaymentModal">
                                                            <i class="fas fa-edit text-primary me-2"></i>Edit Payment
                                                        </a>
                                                    </li>
                                                    <li><hr class="dropdown-divider"></li>
                                                    <li>
                                                        <a class="dropdown-item text-danger" href="#"
                                                           data-id="<?php echo $p['payment_id']; ?>"
                                                           data-bs-toggle="modal" data-bs-target="#voidPaymentModal"
                                                           onclick="setVoidId(this);">
                                                            <i class="fas fa-times me-2"></i>Void Payment
                                                        </a>
                                                    </li>
                                                <?php else: ?>
                                                    <li><span class="dropdown-item text-muted disabled"><i class="fas fa-lock me-2"></i>Locked (<?php echo ucfirst($p['payment_status']); ?>)</span></li>
                                                <?php endif; ?>
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
                            Showing <?php echo $offset + 1; ?> – <?php echo min($offset + $limit, $total_records); ?>
                            of <?php echo number_format($total_records); ?> payments
                        </div>
                        <nav>
                            <ul class="pagination">
                                <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="<?php echo buildPayUrl(1, $limit, $search, $status_filter, $method_filter, $date_from, $date_to); ?>"><i class="fas fa-angle-double-left"></i></a>
                                </li>
                                <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="<?php echo buildPayUrl($page - 1, $limit, $search, $status_filter, $method_filter, $date_from, $date_to); ?>"><i class="fas fa-chevron-left"></i></a>
                                </li>
                                <?php
                                $start = max(1, $page - 2);
                                $end   = min($total_pages, $page + 2);
                                for ($i = $start; $i <= $end; $i++):
                                ?>
                                <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                    <a class="page-link" href="<?php echo buildPayUrl($i, $limit, $search, $status_filter, $method_filter, $date_from, $date_to); ?>"><?php echo $i; ?></a>
                                </li>
                                <?php endfor; ?>
                                <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="<?php echo buildPayUrl($page + 1, $limit, $search, $status_filter, $method_filter, $date_from, $date_to); ?>"><i class="fas fa-chevron-right"></i></a>
                                </li>
                                <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="<?php echo buildPayUrl($total_pages, $limit, $search, $status_filter, $method_filter, $date_from, $date_to); ?>"><i class="fas fa-angle-double-right"></i></a>
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

<!-- ============================================================ -->
<!-- ADD PAYMENT MODAL -->
<!-- ============================================================ -->
<div class="modal fade" id="addPaymentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="action" value="add">
                <input type="hidden" name="page" value="<?php echo $page; ?>">
                <input type="hidden" name="limit" value="<?php echo $limit; ?>">
                <div class="modal-header bg-dark text-white">
                    <h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i>Record New Payment</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Member <span class="text-danger">*</span></label>
                        <select name="member_code" class="form-select" required>
                            <option value="">Select Member</option>
                            <?php foreach ($members as $m): ?>
                            <option value="<?php echo $m['member_code']; ?>">
                                <?php echo htmlspecialchars($m['first_name'] . ' ' . $m['last_name'] . ' (' . $m['member_code'] . ')'); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Amount (₱) <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" class="form-control" name="amount" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Payment Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="payment_date" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Period (Month paid for) <span class="text-danger">*</span></label>
                            <input type="month" class="form-control" name="period_month" value="<?php echo date('Y-m'); ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Method</label>
                            <select name="payment_method" class="form-select">
                                <option value="cash">Cash</option>
                                <option value="gcash">GCash</option>
                                <option value="bank_transfer">Bank Transfer</option>
                            </select>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-12 mb-3">
                            <label class="form-label">GCash Reference</label>
                            <input type="text" class="form-control" name="gcash_reference">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea class="form-control" name="notes" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Save Payment</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ============================================================ -->
<!-- EDIT PAYMENT MODAL -->
<!-- ============================================================ -->
<div class="modal fade" id="editPaymentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="payment_id" id="edit_payment_id">
                <input type="hidden" name="page" value="<?php echo $page; ?>">
                <input type="hidden" name="limit" value="<?php echo $limit; ?>">
                <div class="modal-header bg-dark text-white">
                    <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Edit Payment</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Member <span class="text-danger">*</span></label>
                        <select name="member_code" id="edit_member_code" class="form-select" required>
                            <option value="">Select Member</option>
                            <?php foreach ($members as $m): ?>
                            <option value="<?php echo $m['member_code']; ?>">
                                <?php echo htmlspecialchars($m['first_name'] . ' ' . $m['last_name'] . ' (' . $m['member_code'] . ')'); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Amount (₱) <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" class="form-control" name="amount" id="edit_amount" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Payment Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="payment_date" id="edit_payment_date" required>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Period (Month paid for) <span class="text-danger">*</span></label>
                            <input type="month" class="form-control" name="period_month" id="edit_period_month" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Method</label>
                            <select name="payment_method" id="edit_payment_method" class="form-select">
                                <option value="cash">Cash</option>
                                <option value="gcash">GCash</option>
                                <option value="bank_transfer">Bank Transfer</option>
                            </select>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-12 mb-3">
                            <label class="form-label">GCash Reference</label>
                            <input type="text" class="form-control" name="gcash_reference" id="edit_gcash_reference">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea class="form-control" name="notes" id="edit_notes" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Payment</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ============================================================ -->
<!-- CONFIRM PAYMENT MODAL -->
<!-- ============================================================ -->
<div class="modal fade" id="confirmPaymentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="action" value="confirm">
                <input type="hidden" name="payment_id" id="confirm_payment_id">
                <input type="hidden" name="page" value="<?php echo $page; ?>">
                <input type="hidden" name="limit" value="<?php echo $limit; ?>">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title"><i class="fas fa-check-circle me-2"></i>Confirm Payment</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Confirm this payment? This will update the member's balance and notify them.</p>

                    <div class="mb-3">
                        <label class="form-label">Period (Month paid for) <span class="text-danger">*</span></label>
                        <input type="month" class="form-control" name="period_month" id="confirm_period_month" required>
                        <div class="form-text">Defaults to the period already recorded, or the payment date's month.</div>
                    </div>

                    <div id="confirmProofWrap" style="display:none; margin-top:1rem;">
                        <div class="small text-muted mb-2" style="font-weight: 600;">
                            <i class="fas fa-image me-1"></i>Uploaded proof of payment:
                        </div>
                        <div style="border: 1px solid #dee2e6; border-radius: 8px; overflow: hidden; text-align: center; background: #f8f9fa; padding: 8px;">
                            <img id="confirmProofImg" src="" alt="Payment proof"
                                 style="max-width: 100%; max-height: 380px; border-radius: 4px;">
                        </div>
                        <div class="small text-muted mt-2">
                            Verify this matches the GCash reference number before confirming.
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Confirm Payment</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ============================================================ -->
<!-- VOID PAYMENT MODAL -->
<!-- ============================================================ -->
<div class="modal fade" id="voidPaymentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="action" value="void">
                <input type="hidden" name="payment_id" id="void_payment_id">
                <input type="hidden" name="page" value="<?php echo $page; ?>">
                <input type="hidden" name="limit" value="<?php echo $limit; ?>">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="fas fa-times-circle me-2"></i>Void Payment</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to <strong>void</strong> this payment? This cannot be undone.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Void Payment</button>
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

/* ---------- Edit ---------- */
$(document).on('click', '.edit-trigger', function () {
    const b = $(this);
    $('#edit_payment_id').val(b.data('id'));
    $('#edit_member_code').val(b.data('member'));
    $('#edit_amount').val(b.data('amount'));
    $('#edit_payment_date').val(b.data('date'));
    $('#edit_payment_method').val(b.data('method'));
    $('#edit_gcash_reference').val(b.data('gcash'));
    $('#edit_notes').val(b.data('notes'));

    let period = b.data('period') || '';
    if (!period && b.data('date')) {
        period = String(b.data('date')).substring(0, 7);
    }
    $('#edit_period_month').val(period);
});

/* ---------- Void ---------- */
function setVoidId(el) {
    $('#void_payment_id').val($(el).data('id'));
}

/* ============================================================
   Payment proof preview + period prefill in Confirm modal
============================================================ */
function setConfirmId(el) {
    $('#confirm_payment_id').val($(el).data('id'));

    let period = $(el).data('period') || '';
    if (!period && $(el).data('payment-date')) {
        period = String($(el).data('payment-date')).substring(0, 7);
    }
    if (!period) {
        const now = new Date();
        period = now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0');
    }
    $('#confirm_period_month').val(period);

    const proofPath = $(el).data('proof');
    const wrap = document.getElementById('confirmProofWrap');
    const img  = document.getElementById('confirmProofImg');

    if (proofPath && wrap && img) {
        img.src = '../' + proofPath;
        wrap.style.display = 'block';
    } else if (wrap) {
        wrap.style.display = 'none';
    }
}
</script>
</body>
</html>