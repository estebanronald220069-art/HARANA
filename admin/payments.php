<?php
// admin/payments.php
require_once '../includes/config.php';
require_once '../includes/db_connection.php';
require_once '../includes/security.php';
require_once '../includes/auth.php';
require_once '../includes/user_functions.php'; // ADD THIS LINE

$auth->requireLogin();
$current_user = $auth->getCurrentUser();
$db = Database::getInstance();

// Get pending users count (for sidebar badge)
$pending_count = 0;
if ($current_user['role'] === 'admin') {
    $pending_count = $db->getSingle("SELECT COUNT(*) as cnt FROM pending_users WHERE status = 'pending'")['cnt'] ?? 0;
}

$message = '';
$error = '';

// Pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 15;
$offset = ($page - 1) * $limit;

// Filters
$search = isset($_GET['search']) ? Security::sanitize($_GET['search']) : '';
$status_filter = isset($_GET['status']) ? Security::sanitize($_GET['status']) : '';
$date_from = isset($_GET['date_from']) ? Security::sanitize($_GET['date_from']) : '';
$date_to = isset($_GET['date_to']) ? Security::sanitize($_GET['date_to']) : '';

// Build WHERE clause
$conditions = [];
$params = [];
$types = '';

if (!empty($search)) {
    $conditions[] = "(m.first_name LIKE ? OR m.last_name LIKE ? OR p.receipt_number LIKE ?)";
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $types .= 'sss';
}
if (!empty($status_filter)) {
    $conditions[] = "p.payment_status = ?";
    $params[] = $status_filter;
    $types .= 's';
}
if (!empty($date_from)) {
    $conditions[] = "p.payment_date >= ?";
    $params[] = $date_from;
    $types .= 's';
}
if (!empty($date_to)) {
    $conditions[] = "p.payment_date <= ?";
    $params[] = $date_to;
    $types .= 's';
}

$where_sql = '';
if (!empty($conditions)) {
    $where_sql = 'WHERE ' . implode(' AND ', $conditions);
}

// Count total records
$count_sql = "SELECT COUNT(*) as total FROM payments p
              JOIN members m ON p.member_id = m.member_code
              $where_sql";
$count_result = $db->getSingle($count_sql, $params, $types);
$total_records = $count_result['total'] ?? 0;
$total_pages = ceil($total_records / $limit);

// Fetch payments
$sql = "SELECT p.*, 
               m.first_name, m.last_name, m.member_code,
               u.full_name as confirmed_by_name
        FROM payments p
        JOIN members m ON p.member_id = m.member_code
        LEFT JOIN users u ON p.confirmed_by = u.user_id
        $where_sql
        ORDER BY p.created_at DESC
        LIMIT ? OFFSET ?";
$query_params = array_merge($params, [$limit, $offset]);
$query_types = $types . 'ii';
$payments = $db->getAll($sql, $query_params, $query_types);

// Get members for dropdown
$members = $db->getAll("SELECT member_code, first_name, last_name FROM members WHERE status = 'active' ORDER BY first_name");

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'add') {
            // Insert new payment (pending)
            $member_code = Security::sanitize($_POST['member_code'] ?? '');
            $amount = floatval($_POST['amount']);
            $payment_date = $_POST['payment_date'] ?? date('Y-m-d');
            $due_date = null;
            $payment_method = Security::sanitize($_POST['payment_method'] ?? 'cash');
            $gcash_reference = Security::sanitize($_POST['gcash_reference'] ?? '');
            $notes = Security::sanitize($_POST['notes'] ?? '');

            if (empty($member_code) || $amount <= 0) {
                $error = 'Please select a member and enter a valid amount.';
            } else {
                // Generate UUID and receipt number
                $payment_uuid = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                    mt_rand(0, 0xffff), mt_rand(0, 0xffff),
                    mt_rand(0, 0xffff),
                    mt_rand(0, 0x0fff) | 0x4000,
                    mt_rand(0, 0x3fff) | 0x8000,
                    mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
                );
                $receipt_number = 'RCP-' . date('Ymd') . '-' . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);

                $insert_sql = "INSERT INTO payments 
                    (payment_uuid, member_id, payment_date, amount, due_date, payment_method, gcash_reference, payment_status, receipt_number, notes, ip_address)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?)";
                $insert_params = [$payment_uuid, $member_code, $payment_date, $amount, $due_date, $payment_method, $gcash_reference, $receipt_number, $notes, Security::getClientIP()];
                $insert_types = 'ssdsssssss';

                $result = $db->execute($insert_sql, $insert_params, $insert_types);
                if ($result) {
                    $message = 'Payment recorded successfully (pending confirmation).';
                    Security::logEvent('PAYMENT_ADD', "Added payment for member $member_code, amount $amount");
                } else {
                    $error = 'Failed to record payment.';
                }
            }
        } elseif ($action === 'edit') {
            $payment_id = (int)$_POST['payment_id'];
            // Only allow editing if payment is still pending
            $payment = $db->getSingle("SELECT payment_status FROM payments WHERE payment_id = ?", [$payment_id], 'i');
            if (!$payment || $payment['payment_status'] !== 'pending') {
                $error = 'Only pending payments can be edited.';
            } else {
                $member_code = Security::sanitize($_POST['member_code'] ?? '');
                $amount = floatval($_POST['amount']);
                $payment_date = $_POST['payment_date'];
                $due_date = null;
                $payment_method = Security::sanitize($_POST['payment_method'] ?? 'cash');
                $gcash_reference = Security::sanitize($_POST['gcash_reference'] ?? '');
                $notes = Security::sanitize($_POST['notes'] ?? '');

                if (empty($member_code) || $amount <= 0) {
                    $error = 'Please select a member and enter a valid amount.';
                } else {
                    $update_sql = "UPDATE payments SET 
                        member_id=?, amount=?, payment_date=?, due_date=?, payment_method=?, gcash_reference=?, notes=?
                        WHERE payment_id=?";
                    $update_params = [$member_code, $amount, $payment_date, $due_date, $payment_method, $gcash_reference, $notes, $payment_id];
                    $update_types = 'sddssssi';

                    $result = $db->execute($update_sql, $update_params, $update_types);
                    if ($result !== false) {
                        $message = 'Payment updated successfully.';
                        Security::logEvent('PAYMENT_EDIT', "Edited payment ID $payment_id");
                    } else {
                        $error = 'Failed to update payment.';
                    }
                }
            }
        } elseif ($action === 'confirm') {
            $payment_id = (int)$_POST['payment_id'];
            // Check if payment exists and is pending
            $payment = $db->getSingle("SELECT * FROM payments WHERE payment_id = ? AND payment_status = 'pending'", [$payment_id], 'i');
            if (!$payment) {
                $error = 'Payment not found or already confirmed.';
            } else {
                // Update payment status
                $update_sql = "UPDATE payments SET payment_status='confirmed', confirmed_by=?, confirmed_date=NOW() WHERE payment_id=?";
                $result = $db->execute($update_sql, [$current_user['user_id'], $payment_id], 'ii');
                
                if ($result) {
                    // Get member details for notification
                    $member_details = $db->getSingle(
                        "SELECT user_id, first_name, last_name, member_code FROM members WHERE member_code = ?",
                        [$payment['member_id']], 's'
                    );
                    
                    // Update member_balances
                    $balance = $db->getSingle("SELECT * FROM member_balances WHERE member_code = ?", [$payment['member_id']], 's');
                    if ($balance) {
                        // Update existing
                        $new_total_paid = $balance['total_paid'] + $payment['amount'];
                        $new_current_balance = $balance['total_due'] - $new_total_paid;
                        $db->execute(
                            "UPDATE member_balances SET total_paid = ?, current_balance = ?, last_payment_date = ? WHERE member_code = ?",
                            [$new_total_paid, $new_current_balance, $payment['payment_date'], $payment['member_id']],
                            'ddss'
                        );
                    } else {
                        // Insert new balance record
                        $db->execute(
                            "INSERT INTO member_balances (member_code, total_paid, current_balance, last_payment_date) VALUES (?, ?, ?, ?)",
                            [$payment['member_id'], $payment['amount'], -$payment['amount'], $payment['payment_date']],
                            'sddss'
                        );
                    }
                    
                    // Create notification for the member
                    if ($member_details && $member_details['user_id']) {
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
                    
                    // Delete any payment reminders for this member
                    if ($member_details && $member_details['user_id']) {
                        deletePaidReminders($db, $member_details['user_id']);
                    }
                    
                    $message = 'Payment confirmed successfully.';
                    Security::logEvent('PAYMENT_CONFIRM', "Confirmed payment ID $payment_id");
                } else {
                    $error = 'Failed to confirm payment.';
                }
            }
        } elseif ($action === 'void') {
            $payment_id = (int)$_POST['payment_id'];
            // Only allow void if pending
            $payment = $db->getSingle("SELECT payment_status FROM payments WHERE payment_id = ?", [$payment_id], 'i');
            if (!$payment || $payment['payment_status'] !== 'pending') {
                $error = 'Only pending payments can be voided.';
            } else {
                $result = $db->execute("UPDATE payments SET payment_status='failed' WHERE payment_id=?", [$payment_id], 'i');
                if ($result) {
                    $message = 'Payment voided.';
                    Security::logEvent('PAYMENT_VOID', "Voided payment ID $payment_id");
                } else {
                    $error = 'Failed to void payment.';
                }
            }
        }
    }
}

$csrf_token = Security::generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payments - <?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        *{
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
        
        /* Sidebar - Full width */
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
        
        /* Sidebar - Collapsed state (shows only icons) */
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
        
        /* Hide sidebar logo when collapsed */
        #sidebar-wrapper.collapsed .sidebar-heading img {
            display: none;
        }
        
        #sidebar-wrapper .sidebar-heading {
            padding: 1.2rem 1rem;
            font-size: 1.4rem;
            font-weight: 600;
            letter-spacing: 0.5px;
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
            transition: all 0.3s ease;
        }
        
        /* Hamburger Menu Button - positioned in sidebar heading */
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
        
        /* Header logo - hidden by default, visible only when sidebar is collapsed */
        .header-logo {
            height: 30px;
            width: auto;
            margin-right: 10px;
            vertical-align: middle;
            transition: all 0.3s ease;
            display: none;
        }
        
        /* Show header logo when sidebar is collapsed */
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
        
        .filter-row { background: #f8f9fc; padding: 1rem; border-radius: 10px; margin-bottom: 1.5rem; }
        /* Fix for pending badge positioning */
.list-group-item.position-relative {
    position: relative !important;
    overflow: visible !important;
}

.badge-count {
    position: absolute !important;
    top: 50% !important;
    right: 10px !important;
    left: auto !important;
    transform: translateY(-50%) !important;
    font-size: 0.7rem !important;
    padding: 3px 6px !important;
    border-radius: 10px !important;
    min-width: 20px !important;
    text-align: center !important;
    z-index: 100 !important;
}

/* When sidebar is collapsed */
#sidebar-wrapper.collapsed .badge-count {
    display: none !important;
}

/* For the pending page itself, keep the badge visible */
#sidebar-wrapper .list-group-item.active .badge-count {
    background-color: #dc3545 !important;
    color: white !important;
}
    </style>
</head>
<body>
    <div class="d-flex" id="wrapper">
      

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
                <a href="members.php" class="list-group-item"><i class="fas fa-users"></i><span>Members</span></a>
                <a href="council.php" class="list-group-item"><i class="fas fa-user-tie"></i><span>Council</span></a>
                <a href="payments.php" class="list-group-item active"><i class="fas fa-credit-card"></i><span>Payments</span></a>
                <a href="reports.php" class="list-group-item"><i class="fas fa-chart-bar"></i><span>Reports</span></a>
                <?php if ($current_user['role'] === 'admin'): ?>
                <a href="pending_users.php" class="list-group-item position-relative">
                    <i class="fas fa-user-clock"></i><span>Pending</span>
                    <?php if ($pending_count > 0): ?>
                        <span class="badge bg-danger position-absolute top-0 start-100 translate-middle badge-count"><?php echo $pending_count; ?></span>
                    <?php endif; ?>
                </a>
                <?php endif; ?>
                <a href="announcements.php" class="list-group-item"><i class="fas fa-bullhorn"></i><span>Announcements</span></a>
                <a href="settings.php" class="list-group-item"><i class="fas fa-cog"></i><span>Settings</span></a>
                <a href="../logout.php" class="list-group-item" onclick="return confirm('Are you sure?');"><i class="fas fa-sign-out-alt"></i><span>Logout</span></a>
            </div>
        </div>

        <!-- Main Content -->
        <div class="flex-grow-1">
            <nav class="navbar navbar-light bg-light px-4 py-3">
                <span class="navbar-brand">Payments Management</span>
                <div class="ms-auto">
                    <span><i class="fas fa-user-circle me-2"></i><?php echo htmlspecialchars($current_user['full_name']); ?></span>
                </div>
            </nav>

            <div class="container-fluid p-4">
                <?php if ($message): ?>
                    <div class="alert alert-success alert-dismissible fade show"><?php echo $message; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
                <?php endif; ?>
                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show"><?php echo $error; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
                <?php endif; ?>

                <!-- Filter & Add Button -->
                <div class="filter-row d-flex justify-content-between align-items-center">
                    <form method="GET" class="row g-3 flex-grow-1 me-3">
                        <div class="col-md-3">
                            <label for="search"><b>Search</b></label>
                            <input type="text" name="search" class="form-control" placeholder="Search member or receipt" value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                        <div class="col-md-2">
                            <label for="status"><b>Status</b></label>
                            <select name="status" class="form-select">
                                <option value="">All Status</option>
                                <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="confirmed" <?php echo $status_filter === 'confirmed' ? 'selected' : ''; ?>>Confirmed</option>
                                <option value="failed" <?php echo $status_filter === 'failed' ? 'selected' : ''; ?>>Failed</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label for="date"><b>Date From</b></label>
                            <input type="date" name="date_from" class="form-control" placeholder="From" value="<?php echo $date_from; ?>">
                        </div>
                        <div class="col-md-2">
                            <label for="date"><b>Date To</b></label>
                            <input type="date" name="date_to" class="form-control" placeholder="To" value="<?php echo $date_to; ?>">
                        </div>
                        <div class="col-md-2" style="margin-top: 40px;">
                            <button type="submit" class="btn btn-primary w-100"><i class="fas fa-filter me-2"></i>Filter</button>
                        </div>
                    </form>
                    <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addPaymentModal">
                        <i class="fas fa-plus me-2"></i>Record Payment
                    </button>
                </div>

                <!-- Payments Table -->
                <div class="card">
                    <div class="card-body">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Receipt #</th>
                                    <th>Member</th>
                                    <th>Date</th>
                                    <th>Amount</th>
                                    <th>Method</th>
                                    <th>Status</th>
                                    <th>Confirmed By</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($payments)): ?>
                                    <tr><td colspan="8" class="text-center">No payments found.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($payments as $p): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($p['receipt_number']); ?></td>
                                        <td><?php echo htmlspecialchars($p['first_name'] . ' ' . $p['last_name'] . ' (' . $p['member_code'] . ')'); ?></td>
                                        <td><?php echo date('M d, Y', strtotime($p['payment_date'])); ?></td>
                                        <td>₱<?php echo number_format($p['amount'], 2); ?></td>
                                        <td><?php echo ucfirst($p['payment_method']); ?></td>
                                        <td>
                                            <?php if ($p['payment_status'] == 'confirmed'): ?>
                                                <span class="badge bg-success">Confirmed</span>
                                            <?php elseif ($p['payment_status'] == 'pending'): ?>
                                                <span class="badge bg-warning text-dark">Pending</span>
                                            <?php else: ?>
                                                <span class="badge bg-danger">Failed</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo $p['confirmed_by_name'] ? htmlspecialchars($p['confirmed_by_name']) : '-'; ?></td>
                                        <td>
                                            <?php if ($p['payment_status'] == 'pending'): ?>
                                                <button class="btn btn-sm btn-success confirm-btn" data-id="<?php echo $p['payment_id']; ?>" data-bs-toggle="modal" data-bs-target="#confirmPaymentModal">
                                                    <i class="fas fa-check"></i>
                                                </button>
                                                <button class="btn btn-sm btn-primary edit-btn" 
                                                    data-id="<?php echo $p['payment_id']; ?>"
                                                    data-member="<?php echo $p['member_code']; ?>"
                                                    data-amount="<?php echo $p['amount']; ?>"
                                                    data-date="<?php echo $p['payment_date']; ?>"
                                                    data-method="<?php echo $p['payment_method']; ?>"
                                                    data-gcash="<?php echo htmlspecialchars($p['gcash_reference']); ?>"
                                                    data-notes="<?php echo htmlspecialchars($p['notes']); ?>"
                                                    data-bs-toggle="modal" data-bs-target="#editPaymentModal">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button class="btn btn-sm btn-danger void-btn" data-id="<?php echo $p['payment_id']; ?>" data-bs-toggle="modal" data-bs-target="#voidPaymentModal">
                                                    <i class="fas fa-times"></i>
                                                </button>
                                            <?php else: ?>
                                                <button class="btn btn-sm btn-secondary" disabled><i class="fas fa-check"></i></button>
                                                <button class="btn btn-sm btn-secondary" disabled><i class="fas fa-edit"></i></button>
                                                <button class="btn btn-sm btn-secondary" disabled><i class="fas fa-times"></i></button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>

                        <!-- Pagination -->
                        <?php if ($total_pages > 1): ?>
                        <nav>
                            <ul class="pagination justify-content-center">
                                <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="?page=<?php echo $page-1; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>">Previous</a>
                                </li>
                                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                    <a class="page-link" href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>"><?php echo $i; ?></a>
                                </li>
                                <?php endfor; ?>
                                <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="?page=<?php echo $page+1; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>">Next</a>
                                </li>
                            </ul>
                        </nav>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Payment Modal -->
    <div class="modal fade" id="addPaymentModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="action" value="add">
                    <div class="modal-header">
                        <h5 class="modal-title">Record New Payment</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Member *</label>
                            <select name="member_code" class="form-select" required>
                                <option value="">Select Member</option>
                                <?php foreach ($members as $m): ?>
                                <option value="<?php echo $m['member_code']; ?>"><?php echo htmlspecialchars($m['first_name'] . ' ' . $m['last_name'] . ' (' . $m['member_code'] . ')'); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Amount (₱) *</label>
                                <input type="number" step="0.01" class="form-control" name="amount" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Payment Date *</label>
                                <input type="date" class="form-control" name="payment_date" value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Method</label>
                                <select name="payment_method" class="form-select">
                                    <option value="cash">Cash</option>
                                    <option value="gcash">GCash</option>
                                    <option value="bank_transfer">Bank Transfer</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">GCash Reference (if GCash)</label>
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
                        <button type="submit" class="btn btn-primary">Save Payment</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Payment Modal -->
    <div class="modal fade" id="editPaymentModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="payment_id" id="edit_payment_id">
                    <div class="modal-header">
                        <h5 class="modal-title">Edit Payment</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Member *</label>
                            <select name="member_code" id="edit_member_code" class="form-select" required>
                                <option value="">Select Member</option>
                                <?php foreach ($members as $m): ?>
                                <option value="<?php echo $m['member_code']; ?>"><?php echo htmlspecialchars($m['first_name'] . ' ' . $m['last_name'] . ' (' . $m['member_code'] . ')'); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Amount (₱) *</label>
                                <input type="number" step="0.01" class="form-control" name="amount" id="edit_amount" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Payment Date *</label>
                                <input type="date" class="form-control" name="payment_date" id="edit_payment_date" required>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Method</label>
                                <select name="payment_method" id="edit_payment_method" class="form-select">
                                    <option value="cash">Cash</option>
                                    <option value="gcash">GCash</option>
                                    <option value="bank_transfer">Bank Transfer</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
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

    <!-- Confirm Payment Modal -->
    <div class="modal fade" id="confirmPaymentModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="action" value="confirm">
                    <input type="hidden" name="payment_id" id="confirm_payment_id">
                    <div class="modal-header">
                        <h5 class="modal-title">Confirm Payment</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p>Are you sure you want to confirm this payment? This will update the member's balance.</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success">Confirm Payment</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Void Payment Modal -->
    <div class="modal fade" id="voidPaymentModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="action" value="void">
                    <input type="hidden" name="payment_id" id="void_payment_id">
                    <div class="modal-header">
                        <h5 class="modal-title">Void Payment</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p>Are you sure you want to void this payment? This action cannot be undone.</p>
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
        // Populate edit modal
        $('.edit-btn').on('click', function() {
            var btn = $(this);
            $('#edit_payment_id').val(btn.data('id'));
            $('#edit_member_code').val(btn.data('member'));
            $('#edit_amount').val(btn.data('amount'));
            $('#edit_payment_date').val(btn.data('date'));
            $('#edit_payment_method').val(btn.data('method'));
            $('#edit_gcash_reference').val(btn.data('gcash'));
            $('#edit_notes').val(btn.data('notes'));
        });

        // Set payment id for confirm modal
        $('.confirm-btn').on('click', function() {
            $('#confirm_payment_id').val($(this).data('id'));
        });

        // Set payment id for void modal
        $('.void-btn').on('click', function() {
            $('#void_payment_id').val($(this).data('id'));
        });
    </script>
    <script>
        // Sidebar Toggle Functionality
        const menuToggle = document.getElementById('menuToggle');
        const sidebar = document.getElementById('sidebar-wrapper');
        const headerLogo = document.getElementById('headerLogo');

        const sidebarCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';

        if (sidebarCollapsed) {
            sidebar.classList.add('collapsed');
        }

        menuToggle.addEventListener('click', function() {
            sidebar.classList.toggle('collapsed');
            localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
        });

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