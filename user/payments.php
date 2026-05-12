<?php
// user/payments.php
require_once '../includes/config.php';
require_once '../includes/db_connection.php';
require_once '../includes/security.php';
require_once '../includes/auth.php';
require_once '../includes/user_functions.php';

$auth->requireLogin();
$current_user = $auth->getCurrentUser();

$db = Database::getInstance();
$member = getUserMemberData($db, $current_user);

// Check if member data exists
if (!$member || empty($member['member_code'])) {
    // Try to get member by username if not found
    $member = $db->getSingle(
        "SELECT * FROM members WHERE username = ? OR email = ?",
        [$current_user['username'] ?? '', $current_user['email'] ?? ''],
        'ss'
    );
}

// If still no member, redirect with error
if (!$member || empty($member['member_code'])) {
    header('Location: dashboard.php?error=member_not_found');
    exit();
}

$member_code = $member['member_code'];

// Pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

// Get payments using member_code
$payments = getUserPayments($db, $member_code, $limit, $offset);
$total_payments = getUserPaymentCount($db, $member_code);
$total_pages = ceil($total_payments / $limit);

// Get payment summary
$payment_summary = $db->getSingle(
    "SELECT 
        COUNT(*) as total_count,
        COALESCE(SUM(amount), 0) as total_amount,
        MAX(payment_date) as last_payment,
        MIN(payment_date) as first_payment
     FROM payments p
     JOIN members m ON p.member_id = m.member_code
     WHERE m.member_code = ? AND p.payment_status = 'confirmed'",
    [$member_code], 's'
);

// Get payment methods breakdown
$payment_methods = $db->getAll(
    "SELECT p.payment_method, COUNT(*) as count, SUM(p.amount) as total
     FROM payments p
     JOIN members m ON p.member_id = m.member_code
     WHERE m.member_code = ? AND p.payment_status = 'confirmed'
     GROUP BY p.payment_method",
    [$member_code], 's'
);

// Get member balance and expected
$balance = getUserBalance($db, $member_code);
$months_as_member = calculateMonthsAsMember($member);
$monthly_contribution = $member['monthly_contribution'] ?? 100;
$expected_total = $months_as_member * $monthly_contribution;
$total_paid = $balance['total_paid'] ?? 0;
$current_balance = $expected_total - $total_paid;

$csrf_token = Security::generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment History - <?php echo APP_NAME; ?></title>
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
        .payments-container {
            padding: 20px;
            max-width: 1400px;
            margin: 0 auto;
        }

        /* Summary Cards */
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }

        .summary-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            transition: all 0.3s ease;
        }

        .summary-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.12);
        }

        .summary-icon {
            width: 50px;
            height: 50px;
            background: rgba(55,90,127,0.1);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 12px;
        }

        .summary-icon i {
            font-size: 1.5rem;
            color: #375a7f;
        }

        .summary-number {
            font-size: 1.8rem;
            font-weight: 700;
            color: #2c3e50;
            margin-bottom: 5px;
        }

        .summary-label {
            font-size: 0.75rem;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .summary-sub {
            font-size: 0.7rem;
            color: #6c757d;
            margin-top: 5px;
        }

        /* Balance Card */
        .balance-card {
            background: linear-gradient(135deg, #375a7f, #2c4a6b);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 25px;
            color: white;
        }

        .balance-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        .balance-amount {
            font-size: 1.8rem;
            font-weight: 700;
        }

        .balance-status {
            display: inline-block;
            padding: 5px 12px;
            background: rgba(255,255,255,0.2);
            border-radius: 20px;
            font-size: 0.75rem;
        }

        .balance-progress {
            margin-top: 15px;
        }

        .progress-bar-custom {
            height: 8px;
            background: rgba(255,255,255,0.2);
            border-radius: 4px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            background: #28a745;
            border-radius: 4px;
            transition: width 0.3s ease;
        }

        /* Methods Grid */
        .methods-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }

        .method-card {
            background: white;
            border-radius: 12px;
            padding: 15px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }

        .method-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 10px;
        }

        .method-name {
            font-weight: 600;
            color: #2c3e50;
        }

        .method-badge {
            display: inline-flex;
            align-items: center;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .method-cash {
            background: #d4edda;
            color: #155724;
        }

        .method-gcash {
            background: #cce5ff;
            color: #004085;
        }

        .method-bank_transfer {
            background: #fff3cd;
            color: #856404;
        }

        .method-amount {
            font-size: 1.2rem;
            font-weight: 700;
            color: #28a745;
        }

        .method-count {
            font-size: 0.7rem;
            color: #6c757d;
        }

        .method-progress {
            margin-top: 10px;
            height: 4px;
            background: #e9ecef;
            border-radius: 2px;
            overflow: hidden;
        }

        .method-progress-fill {
            height: 100%;
            background: #375a7f;
            border-radius: 2px;
        }

        /* Main Card */
        .main-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            overflow: hidden;
        }

        .card-header-custom {
            padding: 15px 20px;
            background: #f8f9fa;
            border-bottom: 1px solid #e9ecef;
        }

        .card-header-custom h5 {
            margin: 0;
            font-weight: 600;
            color: #2c3e50;
            font-size: 1rem;
        }

        .card-header-custom i {
            margin-right: 8px;
            color: #375a7f;
        }

        /* Table */
        .table-responsive-custom {
            overflow-x: auto;
        }

        .table-custom {
            width: 100%;
            margin-bottom: 0;
        }

        .table-custom th {
            padding: 12px 15px;
            background: #f8f9fa;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            color: #6c757d;
            border-bottom: 2px solid #e9ecef;
        }

        .table-custom td {
            padding: 12px 15px;
            vertical-align: middle;
            border-bottom: 1px solid #e9ecef;
            font-size: 0.85rem;
        }

        .table-custom tr:hover {
            background: #f8f9fa;
        }

        .receipt-link {
            color: #375a7f;
            text-decoration: none;
            font-size: 0.8rem;
        }

        .receipt-link:hover {
            text-decoration: underline;
        }

        /* Pagination */
        .pagination-custom {
            display: flex;
            justify-content: center;
            gap: 5px;
            margin-top: 20px;
            padding: 15px;
        }

        .page-link-custom {
            padding: 8px 12px;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            color: #375a7f;
            text-decoration: none;
            transition: all 0.2s;
        }

        .page-link-custom:hover {
            background: #375a7f;
            color: white;
            border-color: #375a7f;
        }

        .page-link-custom.active {
            background: #375a7f;
            color: white;
            border-color: #375a7f;
        }

        .page-link-custom.disabled {
            color: #adb5bd;
            pointer-events: none;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 50px 20px;
        }

        .empty-icon {
            font-size: 4rem;
            color: #dee2e6;
            margin-bottom: 15px;
        }

        /* Badge Styles */
        .badge-success {
            background: #d4edda;
            color: #155724;
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 0.7rem;
        }

        .badge-pending {
            background: #fff3cd;
            color: #856404;
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 0.7rem;
        }

        .badge-failed {
            background: #f8d7da;
            color: #721c24;
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 0.7rem;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .summary-grid {
                grid-template-columns: 1fr;
            }
            
            .balance-row {
                flex-direction: column;
                text-align: center;
            }
            
            .methods-grid {
                grid-template-columns: 1fr;
            }
            
            .table-custom th,
            .table-custom td {
                padding: 8px 10px;
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
                <a href="payments.php" class="list-group-item active"><i class="fas fa-credit-card"></i><span>Smart Pay</span></a>
                <a href="beneficiary.php" class="list-group-item"><i class="fas fa-heart"></i><span>Beneficiary</span></a>
                <a href="notifications.php" class="list-group-item"><i class="fas fa-bell"></i><span>Notifications</span></a>
                <a href="organization.php" class="list-group-item"><i class="fas fa-building"></i><span>Organization</span></a>
                <a href="support.php" class="list-group-item"><i class="fas fa-life-ring"></i><span>Support</span></a>
                <a href="../logout.php" class="list-group-item" onclick="return confirm('Are you sure?');"><i class="fas fa-sign-out-alt"></i><span>Logout</span></a>
            </div>
        </div>

        <!-- Main Content -->
        <div id="page-content-wrapper">
            <?php 
            $page_title = 'Smart Pay';
            include '../includes/header.php'; 
            ?>

            <div class="payments-container">
                  <!-- Add this in the header area or wherever you want the payment button -->
<div class="mb-4">
    <a href="gcash_payment.php" class="btn btn-success">
        <i class="fab fa-gcash me-2"></i> Pay with GCash
    </a>
     <br><br>
                <!-- Summary Cards -->
                <div class="summary-grid">
                    <div class="summary-card">
                        <div class="summary-icon">
                            <i class="fas fa-chart-line"></i>
                        </div>
                        <div class="summary-number">₱<?php echo number_format($payment_summary['total_amount'] ?? 0, 2); ?></div>
                        <div class="summary-label">Total Contributions</div>
                    </div>
                    <div class="summary-card">
                        <div class="summary-icon">
                            <i class="fas fa-receipt"></i>
                        </div>
                        <div class="summary-number"><?php echo number_format($payment_summary['total_count'] ?? 0); ?></div>
                        <div class="summary-label">Total Payments</div>
                    </div>
                    <div class="summary-card">
                        <div class="summary-icon">
                            <i class="fas fa-calendar-alt"></i>
                        </div>
                        <div class="summary-number"><?php echo $payment_summary['first_payment'] ? date('M Y', strtotime($payment_summary['first_payment'])) : 'N/A'; ?></div>
                        <div class="summary-label">First Payment</div>
                    </div>
                    <div class="summary-card">
                        <div class="summary-icon">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div class="summary-number"><?php echo $payment_summary['last_payment'] ? date('M d, Y', strtotime($payment_summary['last_payment'])) : 'N/A'; ?></div>
                        <div class="summary-label">Last Payment</div>
                    </div>
                </div>

                <!-- Balance Overview -->
                <div class="balance-card">
                    <div class="balance-row">
                        <div>
                            <div class="balance-amount">₱<?php echo number_format(abs($current_balance), 2); ?></div>
                            <div class="balance-status">
                                <?php if ($current_balance > 0): ?>
                                    <i class="fas fa-exclamation-triangle me-1"></i> Balance Due
                                <?php elseif ($current_balance < 0): ?>
                                    <i class="fas fa-check-circle me-1"></i> In Credit
                                <?php else: ?>
                                    <i class="fas fa-check-circle me-1"></i> Fully Paid
                                <?php endif; ?>
                            </div>
                        </div>
                        <div>
                            <small>Paid: ₱<?php echo number_format($total_paid, 2); ?></small><br>
                            <small>Expected: ₱<?php echo number_format($expected_total, 2); ?></small>
                        </div>
                    </div>
                    <div class="balance-progress">
                        <div class="progress-bar-custom">
                            <div class="progress-fill" style="width: <?php echo $expected_total > 0 ? min(100, ($total_paid / $expected_total) * 100) : 0; ?>%"></div>
                        </div>
                    </div>
                </div>

                <!-- Payment Methods Breakdown -->
                <?php if (!empty($payment_methods)): ?>
                <div class="methods-grid">
                    <?php 
                    $total_method_amount = array_sum(array_column($payment_methods, 'total'));
                    foreach ($payment_methods as $method): 
                        $percentage = $total_method_amount > 0 ? ($method['total'] / $total_method_amount) * 100 : 0;
                    ?>
                    <div class="method-card">
                        <div class="method-header">
                            <span class="method-name"><?php echo ucfirst($method['payment_method']); ?></span>
                            <span class="method-badge method-<?php echo $method['payment_method']; ?>">
                                <i class="fas fa-<?php echo $method['payment_method'] == 'cash' ? 'money-bill' : ($method['payment_method'] == 'gcash' ? 'mobile-alt' : 'university'); ?> me-1"></i>
                                <?php echo ucfirst($method['payment_method']); ?>
                            </span>
                        </div>
                        <div class="method-amount">₱<?php echo number_format($method['total'], 2); ?></div>
                        <div class="method-count"><?php echo $method['count']; ?> transaction(s)</div>
                        <div class="method-progress">
                            <div class="method-progress-fill" style="width: <?php echo $percentage; ?>%"></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <!-- Payment History Table -->
                <div class="main-card">
                    <div class="card-header-custom">
                        <h5><i class="fas fa-list me-2"></i>Transaction History</h5>
                    </div>
                    <div class="table-responsive-custom">
                        <?php if (empty($payments)): ?>
                            <div class="empty-state">
                                <div class="empty-icon">
                                    <i class="fas fa-receipt"></i>
                                </div>
                                <h5>No Payment History</h5>
                                <p class="text-muted">You haven't made any payments yet.</p>
                                <a href="../admin/payments.php?action=add&member_id=<?php echo $member_id; ?>" class="btn btn-primary" style="background: #375a7f; border: none;">
                                    <i class="fas fa-credit-card me-2"></i>Make Your First Payment
                                </a>
                            </div>
                           
                        <?php else: ?>
                            <table class="table-custom">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Receipt #</th>
                                        <th>Amount</th>
                                        <th>Method</th>
                                        <th>Reference</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($payments as $payment): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo date('M d, Y', strtotime($payment['payment_date'])); ?></strong>
                                            <br><small class="text-muted"><?php echo date('h:i A', strtotime($payment['created_at'] ?? $payment['payment_date'])); ?></small>
                                        </td>
                                        <td>
                                            <span class="badge bg-secondary"><?php echo htmlspecialchars($payment['receipt_number'] ?? 'N/A'); ?></span>
                                        </td>
                                        <td class="fw-bold text-success">₱<?php echo number_format($payment['amount'], 2); ?></td>
                                        <td>
                                            <span class="method-badge method-<?php echo $payment['payment_method']; ?>">
                                                <i class="fas fa-<?php echo $payment['payment_method'] == 'cash' ? 'money-bill' : ($payment['payment_method'] == 'gcash' ? 'mobile-alt' : 'university'); ?> me-1"></i>
                                                <?php echo ucfirst($payment['payment_method']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if (!empty($payment['gcash_reference'])): ?>
                                                <small><?php echo htmlspecialchars($payment['gcash_reference']); ?></small>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge-<?php echo $payment['payment_status']; ?>">
                                                <i class="fas fa-<?php echo $payment['payment_status'] == 'confirmed' ? 'check-circle' : ($payment['payment_status'] == 'pending' ? 'clock' : 'times-circle'); ?> me-1"></i>
                                                <?php echo ucfirst($payment['payment_status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($payment['payment_status'] == 'confirmed' && !empty($payment['receipt_number'])): ?>
                                            <a href="#" class="receipt-link" onclick="downloadReceipt('<?php echo $payment['receipt_number']; ?>')">
                                                <i class="fas fa-download"></i> Receipt
                                            </a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>

                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                    <div class="pagination-custom">
                        <a href="?page=1" class="page-link-custom <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                            <i class="fas fa-angle-double-left"></i>
                        </a>
                        <a href="?page=<?php echo $page-1; ?>" class="page-link-custom <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                            <i class="fas fa-angle-left"></i>
                        </a>
                        
                        <?php 
                        $start = max(1, $page - 2);
                        $end = min($total_pages, $page + 2);
                        for ($i = $start; $i <= $end; $i++): 
                        ?>
                        <a href="?page=<?php echo $i; ?>" class="page-link-custom <?php echo $i == $page ? 'active' : ''; ?>">
                            <?php echo $i; ?>
                        </a>
                        <?php endfor; ?>
                        
                        <a href="?page=<?php echo $page+1; ?>" class="page-link-custom <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                            <i class="fas fa-angle-right"></i>
                        </a>
                        <a href="?page=<?php echo $total_pages; ?>" class="page-link-custom <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                            <i class="fas fa-angle-double-right"></i>
                        </a>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($payments)): ?>
                    <div class="text-muted small p-3 border-top">
                        Showing <?php echo count($payments); ?> of <?php echo $total_payments; ?> payments
                    </div>
                    <?php endif; ?>
                </div>
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

        // Download receipt function
        function downloadReceipt(receiptNumber) {
            alert('Downloading receipt: ' + receiptNumber + '\n\nThis feature will be available soon.');
        }
    </script>
</body>
</html>