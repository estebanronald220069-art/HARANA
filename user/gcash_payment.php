<?php
// user/gcash_payment.php - User initiates GCash Payment
require_once '../includes/config.php';
require_once '../includes/db_connection.php';
require_once '../includes/security.php';
require_once '../includes/auth.php';
require_once '../includes/user_functions.php';

$auth->requireLogin();
$current_user = $auth->getCurrentUser();

$db = Database::getInstance();
$member = getUserMemberData($db, $current_user);

if (!$member || empty($member['member_code'])) {
    header('Location: dashboard.php?error=member_not_found');
    exit();
}

$member_code = $member['member_code'];
$member_name = $member['first_name'] . ' ' . $member['last_name'];

// Get pending payment request (if any)
$pending_payment = $db->getSingle(
    "SELECT * FROM payments 
     WHERE member_id = ? AND payment_status = 'pending' 
     ORDER BY created_at DESC LIMIT 1",
    [$member_code], 's'
);

$message = '';
$error = '';
$payment_reference = '';

// Handle payment request submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_payment'])) {
    if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token';
    } else {
        $amount = floatval($_POST['amount'] ?? 0);
        $payment_method = Security::sanitize($_POST['payment_method'] ?? 'gcash');
        $notes = Security::sanitize($_POST['notes'] ?? '');
        
        if ($amount <= 0) {
            $error = 'Please enter a valid amount';
        } else {
            // Generate unique receipt number
            $receipt_number = 'RCP-' . date('Ymd') . '-' . str_pad(mt_rand(1, 9999), 4, '0');
            $payment_uuid = uniqid() . '_' . time();
            
            // Create payment record (pending)
            $result = $db->execute(
                "INSERT INTO payments (payment_uuid, member_id, amount, payment_date, payment_method, 
                 payment_status, receipt_number, notes, ip_address, created_at) 
                 VALUES (?, ?, ?, NOW(), ?, 'pending', ?, ?, ?, NOW())",
                [$payment_uuid, $member_code, $amount, $payment_method, $receipt_number, $notes, Security::getClientIP()],
                'ssdssss'
            );
            
            if ($result) {
                $payment_reference = $receipt_number;
                $message = 'Payment request created! Redirecting to GCash payment...';
                
                // Store in session for the payment page
                $_SESSION['gcash_payment_amount'] = $amount;
                $_SESSION['gcash_payment_reference'] = $receipt_number;
                
                // Redirect to mock GCash payment page
                header('Location: gcash_mock_payment.php?ref=' . $receipt_number);
                exit();
            } else {
                $error = 'Failed to create payment request';
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
    <title>GCash Payment - <?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .payment-card {
            max-width: 500px;
            margin: 50px auto;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 20px 40px rgba(0,0,0,0.2);
        }
        .card-header-gcash {
            background: linear-gradient(135deg, #0078FF, #00C2FF);
            color: white;
            padding: 20px;
            text-align: center;
        }
        .gcash-logo {
            font-size: 2.5rem;
            font-weight: bold;
        }
        .gcash-logo i {
            font-size: 2rem;
            margin-right: 10px;
        }
        .amount-input {
            font-size: 2rem;
            text-align: center;
            font-weight: bold;
        }
        .btn-gcash {
            background: linear-gradient(135deg, #0078FF, #00C2FF);
            color: white;
            border: none;
            padding: 12px;
            font-weight: 600;
            width: 100%;
            border-radius: 10px;
            transition: all 0.3s;
        }
        .btn-gcash:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,120,255,0.4);
        }
        .btn-gcash:disabled {
            background: #ccc;
            transform: none;
        }
        .payment-method {
            border: 2px solid #e9ecef;
            border-radius: 12px;
            padding: 15px;
            cursor: pointer;
            transition: all 0.3s;
        }
        .payment-method.active {
            border-color: #0078FF;
            background: rgba(0,120,255,0.05);
        }
        .payment-method i {
            font-size: 1.5rem;
            margin-right: 10px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="payment-card">
            <div class="card-header-gcash">
                <div class="gcash-logo">
                    <i class="fas fa-mobile-alt"></i> GCash
                </div>
                <p class="mb-0">Pay with GCash - Fast, Secure, Convenient</p>
            </div>
            <div class="card-body bg-white p-4">
                <?php if ($message): ?>
                    <div class="alert alert-info"><?php echo $message; ?></div>
                <?php endif; ?>
                <?php if ($error): ?>
                    <div class="alert alert-danger"><?php echo $error; ?></div>
                <?php endif; ?>
                
                <?php if ($pending_payment && $pending_payment['payment_status'] === 'pending'): ?>
                    <div class="alert alert-warning">
                        <i class="fas fa-clock me-2"></i>
                        You have a pending payment request (₱<?php echo number_format($pending_payment['amount'], 2); ?>).
                        <a href="gcash_mock_payment.php?ref=<?php echo $pending_payment['receipt_number']; ?>" class="alert-link">Continue payment</a>
                    </div>
                <?php endif; ?>
                
                <form method="POST" id="paymentForm">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    
                    <div class="mb-4">
                        <label class="form-label">Member Information</label>
                        <div class="bg-light p-3 rounded">
                            <div><strong><?php echo htmlspecialchars($member_name); ?></strong></div>
                            <div><small class="text-muted">Member Code: <?php echo htmlspecialchars($member_code); ?></small></div>
                        </div>
                    </div>
                    
                    <div class="mb-4">
                        <label class="form-label">Select Payment Method</label>
                        <div class="payment-method active" data-method="gcash" onclick="selectMethod('gcash')">
                            <i class="fab fa-gcash text-primary"></i> GCash
                            <i class="fas fa-check-circle float-end text-success" style="display: none;"></i>
                        </div>
                        <div class="payment-method mt-2" data-method="cash" onclick="selectMethod('cash')">
                            <i class="fas fa-money-bill text-success"></i> Cash (Pay at Office)
                            <i class="fas fa-check-circle float-end text-success" style="display: none;"></i>
                        </div>
                        <input type="hidden" name="payment_method" id="payment_method" value="gcash">
                    </div>
                    
                    <div class="mb-4">
                        <label class="form-label">Amount to Pay (₱)</label>
                        <input type="number" step="0.01" class="form-control amount-input" name="amount" 
                               value="<?php echo $member['monthly_contribution'] ?? 100; ?>" required>
                        <small class="text-muted">Monthly contribution: ₱<?php echo number_format($member['monthly_contribution'] ?? 100, 2); ?></small>
                    </div>
                    
                    <div class="mb-4">
                        <label class="form-label">Notes (Optional)</label>
                        <textarea class="form-control" name="notes" rows="2" placeholder="e.g., Payment for March 2026"></textarea>
                    </div>
                    
                    <button type="submit" name="request_payment" class="btn-gcash">
                        <i class="fas fa-mobile-alt me-2"></i> Pay with GCash
                    </button>
                </form>
                
                <hr class="my-4">
                
                <div class="text-center">
                    <small class="text-muted">
                        <i class="fas fa-shield-alt me-1"></i> Secure payment powered by GCash
                    </small>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        function selectMethod(method) {
            document.querySelectorAll('.payment-method').forEach(el => {
                el.classList.remove('active');
                el.querySelector('.fa-check-circle').style.display = 'none';
            });
            document.querySelector(`.payment-method[data-method="${method}"]`).classList.add('active');
            document.querySelector(`.payment-method[data-method="${method}"] .fa-check-circle`).style.display = 'block';
            document.getElementById('payment_method').value = method;
            
            if (method === 'cash') {
                document.querySelector('.btn-gcash').innerHTML = '<i class="fas fa-money-bill me-2"></i> Proceed to Office Payment';
                document.querySelector('.btn-gcash').classList.remove('btn-gcash');
                document.querySelector('.btn-gcash').classList.add('btn-success');
            } else {
                document.querySelector('.btn-gcash').innerHTML = '<i class="fas fa-mobile-alt me-2"></i> Pay with GCash';
                document.querySelector('.btn-gcash').classList.remove('btn-success');
                document.querySelector('.btn-gcash').classList.add('btn-gcash');
            }
        }
    </script>
</body>
</html>