<?php
// user/gcash_payment_success.php - Payment Success Confirmation
require_once '../includes/config.php';
require_once '../includes/db_connection.php';
require_once '../includes/security.php';
require_once '../includes/auth.php';
require_once '../includes/user_functions.php';

$auth->requireLogin();
$current_user = $auth->getCurrentUser();

$db = Database::getInstance();
$member = getUserMemberData($db, $current_user);

$reference = isset($_GET['ref']) ? Security::sanitize($_GET['ref']) : '';

// Get payment details
$payment = $db->getSingle(
    "SELECT * FROM payments WHERE receipt_number = ? AND member_id = ?",
    [$reference, $member['member_code']],
    'ss'
);

if (!$payment) {
    header('Location: payments.php');
    exit();
}

// Get updated balance
$balance = getUserBalance($db, $member['member_code']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Successful - <?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .success-card {
            max-width: 500px;
            width: 100%;
            margin: 20px;
            background: white;
            border-radius: 24px;
            overflow: hidden;
            box-shadow: 0 20px 40px rgba(0,0,0,0.2);
            text-align: center;
            animation: slideUp 0.5s ease;
        }
        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        .success-header {
            background: linear-gradient(135deg, #28a745, #20c997);
            padding: 40px;
            color: white;
        }
        .success-icon {
            font-size: 4rem;
            margin-bottom: 15px;
        }
        .success-body {
            padding: 30px;
        }
        .receipt-box {
            background: #f8f9fa;
            border-radius: 16px;
            padding: 20px;
            margin: 20px 0;
            text-align: left;
        }
        .receipt-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #e9ecef;
        }
        .receipt-row:last-child {
            border-bottom: none;
        }
        .btn-dashboard {
            background: #375a7f;
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: 30px;
            font-weight: 600;
            margin: 10px;
            text-decoration: none;
            display: inline-block;
        }
        .btn-dashboard:hover {
            background: #2c4a6b;
            color: white;
        }
        .btn-print {
            background: #6c757d;
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: 30px;
            font-weight: 600;
            margin: 10px;
            text-decoration: none;
            display: inline-block;
        }
    </style>
</head>
<body>
    <div class="success-card">
        <div class="success-header">
            <div class="success-icon">
                <i class="fas fa-check-circle"></i>
            </div>
            <h3>Payment Successful!</h3>
            <p>Your transaction has been completed</p>
        </div>
        
        <div class="success-body">
            <div class="receipt-box">
                <h6 class="text-center mb-3"><i class="fas fa-receipt me-2"></i>Payment Receipt</h6>
                <div class="receipt-row">
                    <span>Receipt Number:</span>
                    <strong><?php echo $payment['receipt_number']; ?></strong>
                </div>
                <div class="receipt-row">
                    <span>Date & Time:</span>
                    <strong><?php echo date('F j, Y h:i A', strtotime($payment['confirmed_date'] ?? $payment['created_at'])); ?></strong>
                </div>
                <div class="receipt-row">
                    <span>Payment Method:</span>
                    <strong>GCash</strong>
                </div>
                <div class="receipt-row">
                    <span>Transaction ID:</span>
                    <strong><?php echo $payment['gcash_transaction_id'] ?? 'N/A'; ?></strong>
                </div>
                <div class="receipt-row">
                    <span>Amount Paid:</span>
                    <strong class="text-success">₱<?php echo number_format($payment['amount'], 2); ?></strong>
                </div>
                <div class="receipt-row">
                    <span>Payment Status:</span>
                    <strong class="text-success">Confirmed</strong>
                </div>
            </div>
            
            <div class="mt-3">
                <a href="payments.php" class="btn-dashboard">
                    <i class="fas fa-credit-card me-2"></i> View Payment History
                </a>
                <a href="dashboard.php" class="btn-dashboard">
                    <i class="fas fa-home me-2"></i> Back to Dashboard
                </a>
                <button onclick="window.print()" class="btn-print">
                    <i class="fas fa-print me-2"></i> Print Receipt
                </button>
            </div>
        </div>
    </div>
</body>
</html>