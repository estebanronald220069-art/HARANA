<?php
// user/gcash_mock_payment.php - Mock GCash Payment Interface
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
    "SELECT * FROM payments WHERE receipt_number = ? AND member_id = ? AND payment_status = 'pending'",
    [$reference, $member['member_code']],
    'ss'
);

if (!$payment) {
    header('Location: gcash_payment.php?error=payment_not_found');
    exit();
}

$message = '';
$error = '';

// Handle payment confirmation (MOCK)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_payment'])) {
    if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token';
    } else {
        // SIMULATE GCASH PAYMENT CONFIRMATION
        // In real implementation, this would call GCash API
        
        // Generate mock transaction ID
        $mock_transaction_id = 'GCASH-' . date('Ymd') . '-' . str_pad(mt_rand(1, 9999), 4, '0');
        
        // Update payment status
        $db->execute(
            "UPDATE payments SET payment_status = 'confirmed', confirmed_by = ?, confirmed_date = NOW(), 
             gcash_transaction_id = ?, gcash_payment_time = NOW() 
             WHERE payment_id = ?",
            [$current_user['user_id'], $mock_transaction_id, $payment['payment_id']],
            'isi'
        );
        
        // Update member balance
        $balance = $db->getSingle(
            "SELECT * FROM member_balances WHERE member_code = ?",
            [$member['member_code']], 's'
        );
        
        if ($balance) {
            $new_total_paid = ($balance['total_paid'] ?? 0) + $payment['amount'];
            $new_current_balance = ($balance['total_due'] ?? 0) - $new_total_paid;
            $db->execute(
                "UPDATE member_balances SET total_paid = ?, current_balance = ?, last_payment_date = NOW() 
                 WHERE member_code = ?",
                [$new_total_paid, $new_current_balance, $member['member_code']],
                'dds'
            );
        } else {
            $db->execute(
                "INSERT INTO member_balances (member_code, total_paid, current_balance, last_payment_date) 
                 VALUES (?, ?, ?, NOW())",
                [$member['member_code'], $payment['amount'], -$payment['amount']],
                'sdd'
            );
        }
        
        // Create notification
        createNotification(
            $db,
            $current_user['user_id'],
            "Payment Confirmed",
            "Your GCash payment of ₱" . number_format($payment['amount'], 2) . 
            " has been confirmed. Reference: " . $mock_transaction_id,
            'payment',
            'payments.php'
        );
        
        // Redirect to success page
        header('Location: gcash_payment_success.php?ref=' . $payment['receipt_number']);
        exit();
    }
}

$csrf_token = Security::generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GCash Payment - <?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .gcash-payment-container {
            max-width: 450px;
            margin: 30px auto;
        }
        .gcash-card {
            background: white;
            border-radius: 24px;
            overflow: hidden;
            box-shadow: 0 20px 40px rgba(0,0,0,0.2);
        }
        .gcash-header {
            background: linear-gradient(135deg, #0078FF, #00C2FF);
            padding: 25px;
            text-align: center;
            color: white;
        }
        .gcash-logo {
            font-size: 2rem;
            font-weight: bold;
        }
        .payment-details {
            padding: 20px;
            background: #f8f9fa;
        }
        .merchant-info {
            text-align: center;
            padding: 20px;
            border-bottom: 1px solid #e9ecef;
        }
        .merchant-logo {
            width: 60px;
            height: 60px;
            background: #375a7f;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 10px;
            color: white;
            font-size: 1.5rem;
        }
        .amount-display {
            font-size: 2.5rem;
            font-weight: 700;
            color: #2c3e50;
            text-align: center;
            padding: 15px;
            background: white;
            border-radius: 16px;
            margin: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        .mock-qr {
            background: white;
            padding: 20px;
            text-align: center;
            border-radius: 16px;
            margin: 20px;
            border: 2px dashed #0078FF;
        }
        .mock-qr i {
            font-size: 5rem;
            color: #0078FF;
        }
        .btn-confirm {
            background: linear-gradient(135deg, #28a745, #20c997);
            color: white;
            border: none;
            padding: 15px;
            font-weight: 600;
            width: calc(100% - 40px);
            margin: 0 20px 20px;
            border-radius: 12px;
            transition: all 0.3s;
        }
        .btn-confirm:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(40,167,69,0.4);
        }
        .back-link {
            text-align: center;
            padding: 15px;
            color: #6c757d;
            text-decoration: none;
            display: block;
        }
        .instruction-step {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 12px;
            border-bottom: 1px solid #e9ecef;
        }
        .step-number {
            width: 30px;
            height: 30px;
            background: #0078FF;
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <div class="gcash-payment-container">
        <div class="gcash-card">
            <div class="gcash-header">
                <div class="gcash-logo">
                    <i class="fas fa-mobile-alt"></i> GCash
                </div>
                <p class="mb-0">Secure Payment Gateway</p>
            </div>
            
            <div class="payment-details">
                <div class="merchant-info">
                    <div class="merchant-logo">
                        <i class="fas fa-hand-holding-heart"></i>
                    </div>
                    <h5>Harana Financial System</h5>
                    <small class="text-muted">Member: <?php echo htmlspecialchars($member['first_name'] . ' ' . $member['last_name']); ?></small>
                </div>
                
                <div class="amount-display">
                    ₱<?php echo number_format($payment['amount'], 2); ?>
                </div>
                
                <div class="mock-qr">
                    <i class="fas fa-qrcode"></i>
                    <p class="mt-2 fw-bold">Scan to Pay</p>
                    <small class="text-muted">Reference: <?php echo $payment['receipt_number']; ?></small>
                </div>
                
                <div class="px-3">
                    <p class="fw-bold mb-2"><i class="fas fa-info-circle"></i> How to pay:</p>
                    <div class="instruction-step">
                        <div class="step-number">1</div>
                        <div>Open GCash app on your phone</div>
                    </div>
                    <div class="instruction-step">
                        <div class="step-number">2</div>
                        <div>Tap "Pay QR" and scan the QR code above</div>
                    </div>
                    <div class="instruction-step">
                        <div class="step-number">3</div>
                        <div>Enter amount: ₱<?php echo number_format($payment['amount'], 2); ?></div>
                    </div>
                    <div class="instruction-step">
                        <div class="step-number">4</div>
                        <div>Confirm payment with your MPIN</div>
                    </div>
                    <div class="instruction-step">
                        <div class="step-number">5</div>
                        <div>Click "Confirm Payment" below after completing payment</div>
                    </div>
                </div>
                
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <button type="submit" name="confirm_payment" class="btn-confirm">
                        <i class="fas fa-check-circle me-2"></i> I Have Completed the Payment
                    </button>
                </form>
                
                <a href="gcash_payment.php" class="back-link">
                    <i class="fas fa-arrow-left me-1"></i> Cancel and Go Back
                </a>
            </div>
        </div>
    </div>
</body>
</html>