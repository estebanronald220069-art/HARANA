<?php
// user/gcash_payment.php - GCash Auto-Pay with Deep Link
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
    $member = $db->getSingle(
        "SELECT * FROM members WHERE username = ? OR email = ?",
        [$current_user['username'] ?? '', $current_user['email'] ?? ''],
        'ss'
    );
}

if (!$member || empty($member['member_code'])) {
    header('Location: dashboard.php?error=member_not_found');
    exit();
}

$member_code = $member['member_code'];
$member_name = $member['first_name'] . ' ' . $member['last_name'];

// Get current balance for recommended payment
$balance = getUserBalance($db, $member_code);
$months_as_member = calculateMonthsAsMember($member);
$monthly_contribution = $member['monthly_contribution'] ?? 100;
$expected_total = $months_as_member * $monthly_contribution;
$total_paid = $balance['total_paid'] ?? 0;
$current_balance = $expected_total - $total_paid;

// Recommended amount
$recommended_amount = $current_balance > 0 ? $current_balance : $monthly_contribution;

// GCash Account Details (from the QR code info)
$GCASH_ACCOUNT = [
    'mobile' => '0928867551',
    'account_name' => 'RO***LE',
    'user_id' => '4LFEZX'
];

$error = '';
$success = '';

// Check for payment callback from GCash
$payment_ref = isset($_GET['ref']) ? Security::sanitize($_GET['ref']) : '';
$payment_status = isset($_GET['status']) ? Security::sanitize($_GET['status']) : '';

if ($payment_ref && $payment_status) {
    if ($payment_status == 'success') {
        $pending = $db->getSingle(
            "SELECT * FROM payments WHERE receipt_number = ? AND payment_status = 'pending'",
            [$payment_ref], 's'
        );
        
        if ($pending) {
            $db->execute(
                "UPDATE payments SET payment_status = 'confirmed', confirmed_by = ?, confirmed_date = NOW() WHERE payment_id = ?",
                [1, $pending['payment_id']],
                'ii'
            );
            
            // Update member balance
            $balance_update = $db->getSingle(
                "SELECT * FROM member_balances WHERE member_code = ?",
                [$pending['member_id']], 's'
            );
            
            if ($balance_update) {
                $new_total_paid = $balance_update['total_paid'] + $pending['amount'];
                $new_current_balance = $balance_update['total_due'] - $new_total_paid;
                $db->execute(
                    "UPDATE member_balances SET total_paid = ?, current_balance = ?, last_payment_date = NOW() WHERE member_code = ?",
                    [$new_total_paid, $new_current_balance, $pending['member_id']],
                    'dds'
                );
            }
            
            if (!empty($member['user_id'])) {
                createNotification(
                    $db,
                    $member['user_id'],
                    "Payment Confirmed via GCash",
                    "Your GCash payment of ₱" . number_format($pending['amount'], 2) . 
                    " has been confirmed. Receipt #: " . $pending['receipt_number'],
                    'payment',
                    '../user/payments.php'
                );
            }
            
            header('Location: payments.php?success=gcash_confirmed');
            exit();
        }
    } elseif ($payment_status == 'failed') {
        header('Location: payments.php?error=gcash_failed');
        exit();
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token.';
    } else {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'initiate_payment') {
            $amount = floatval($_POST['amount'] ?? 0);
            
            if ($amount <= 0) {
                $error = 'Please enter a valid payment amount.';
            } else {
                $receipt_number = 'GCASH-' . date('Ymd') . '-' . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
                $payment_uuid = uniqid('gcash_') . '_' . time();
                
                $insert_sql = "INSERT INTO payments (
                    payment_uuid, member_id, payment_date, amount, 
                    payment_method, gcash_reference, gcash_transaction_id, payment_status, 
                    receipt_number, notes, ip_address, created_at
                ) VALUES (?, ?, NOW(), ?, 'gcash', ?, ?, 'pending', ?, ?, ?, NOW())";
                
                $insert_params = [
                    $payment_uuid,
                    $member_code,
                    $amount,
                    $receipt_number,
                    $receipt_number,
                    $receipt_number,
                    'Auto-pay via GCash',
                    Security::getClientIP()
                ];
                
                $insert_types = 'ssdsssss';
                $result = $db->execute($insert_sql, $insert_params, $insert_types);
                
                if ($result) {
                    Security::logEvent('PAYMENT_GCASH_INITIATED', "GCash payment initiated: $receipt_number");
                    header('Location: gcash_payment.php?action=confirm&ref=' . $receipt_number);
                    exit();
                } else {
                    $error = 'Failed to initiate payment. Please try again.';
                }
            }
        } elseif ($action === 'confirm_payment') {
            $receipt_ref = Security::sanitize($_POST['receipt_ref'] ?? '');
            
            if (empty($receipt_ref)) {
                $error = 'Invalid payment reference.';
            } else {
                $payment = $db->getSingle(
                    "SELECT * FROM payments WHERE receipt_number = ? AND payment_status = 'pending'",
                    [$receipt_ref], 's'
                );
                
                if (!$payment) {
                    $error = 'Payment not found or already processed.';
                } else {
                    $db->execute(
                        "UPDATE payments SET 
                            payment_status = 'confirmed', 
                            confirmed_by = ?, 
                            confirmed_date = NOW(),
                            gcash_payment_time = NOW()
                        WHERE payment_id = ?",
                        [1, $payment['payment_id']],
                        'ii'
                    );
                    
                    // Update member balance
                    $balance_update = $db->getSingle(
                        "SELECT * FROM member_balances WHERE member_code = ?",
                        [$payment['member_id']], 's'
                    );
                    
                    if ($balance_update) {
                        $new_total_paid = $balance_update['total_paid'] + $payment['amount'];
                        $new_current_balance = $balance_update['total_due'] - $new_total_paid;
                        $db->execute(
                            "UPDATE member_balances SET total_paid = ?, current_balance = ?, last_payment_date = NOW() WHERE member_code = ?",
                            [$new_total_paid, $new_current_balance, $payment['member_id']],
                            'dds'
                        );
                    }
                    
                    if (!empty($member['user_id'])) {
                        createNotification(
                            $db,
                            $member['user_id'],
                            "Payment Confirmed via GCash",
                            "Your GCash payment of ₱" . number_format($payment['amount'], 2) . 
                            " has been confirmed. Receipt #: " . $payment['receipt_number'],
                            'payment',
                            '../user/payments.php'
                        );
                    }
                    
                    unset($_SESSION['pending_gcash_payment']);
                    header('Location: payments.php?success=gcash_confirmed');
                    exit();
                }
            }
        }
    }
}

// Check if we're in confirmation mode
$confirm_mode = isset($_GET['action']) && $_GET['action'] === 'confirm';
$receipt_ref = isset($_GET['ref']) ? Security::sanitize($_GET['ref']) : '';

$csrf_token = Security::generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GCash Auto-Pay - <?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body { background: #f4f7fc; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; min-height: 100vh; display: flex; align-items: center; }
        .payment-container { max-width: 550px; margin: 0 auto; padding: 20px; }
        .payment-card { background: white; border-radius: 20px; box-shadow: 0 10px 40px rgba(0,0,0,0.12); overflow: hidden; }
        .payment-header { background: linear-gradient(135deg, #00b4d8, #0077b6); padding: 25px 30px; color: white; text-align: center; }
        .payment-header i { font-size: 3.5rem; margin-bottom: 10px; }
        .payment-header h3 { font-weight: 700; margin-bottom: 5px; }
        .payment-header p { opacity: 0.9; margin-bottom: 0; font-size: 0.9rem; }
        .payment-body { padding: 30px; }
        .gcash-logo { display: flex; align-items: center; justify-content: center; gap: 10px; background: #f0f8ff; padding: 15px; border-radius: 12px; margin-bottom: 20px; }
        .gcash-logo i { font-size: 2rem; color: #00b4d8; }
        .gcash-logo span { font-weight: 700; color: #00b4d8; font-size: 1.2rem; }
        .amount-display { text-align: center; padding: 20px; background: linear-gradient(135deg, #f8f9fa, #e9ecef); border-radius: 12px; margin-bottom: 20px; }
        .amount-display .label { font-size: 0.8rem; color: #6c757d; text-transform: uppercase; letter-spacing: 0.5px; }
        .amount-display .amount { font-size: 2.5rem; font-weight: 700; color: #2c3e50; }
        .amount-display .amount span { color: #28a745; }
        .form-control { padding: 12px 15px; border: 2px solid #e9ecef; border-radius: 10px; transition: all 0.3s; }
        .form-control:focus { border-color: #00b4d8; box-shadow: 0 0 0 0.2rem rgba(0, 180, 216, 0.2); }
        .form-label { font-weight: 600; color: #2c3e50; font-size: 0.9rem; }
        .btn-gcash-pay { background: linear-gradient(135deg, #00b4d8, #0077b6); color: white; border: none; padding: 15px; font-size: 1.1rem; font-weight: 600; border-radius: 10px; width: 100%; transition: all 0.3s; display: flex; align-items: center; justify-content: center; gap: 12px; }
        .btn-gcash-pay:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(0, 180, 216, 0.4); color: white; }
        .btn-gcash-pay i { font-size: 1.5rem; }
        .btn-confirm { background: linear-gradient(135deg, #28a745, #20c997); color: white; border: none; padding: 15px; font-size: 1.1rem; font-weight: 600; border-radius: 10px; width: 100%; transition: all 0.3s; }
        .btn-confirm:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(40, 167, 69, 0.4); color: white; }
        .btn-back { background: #6c757d; color: white; border: none; padding: 12px 25px; border-radius: 10px; font-weight: 500; transition: all 0.3s; text-decoration: none; display: inline-block; }
        .btn-back:hover { background: #5a6268; color: white; }
        .step-indicator { display: flex; justify-content: center; gap: 30px; margin: 20px 0; }
        .step { display: flex; flex-direction: column; align-items: center; font-size: 0.7rem; color: #adb5bd; position: relative; }
        .step.active { color: #00b4d8; }
        .step.done { color: #28a745; }
        .step .circle { width: 35px; height: 35px; border-radius: 50%; background: #e9ecef; display: flex; align-items: center; justify-content: center; font-weight: 700; margin-bottom: 5px; transition: all 0.3s; }
        .step.active .circle { background: #00b4d8; color: white; }
        .step.done .circle { background: #28a745; color: white; }
        .step-connector { position: absolute; top: 17px; left: 40px; width: 60px; height: 2px; background: #e9ecef; }
        .step.active .step-connector { background: #00b4d8; }
        .step.done .step-connector { background: #28a745; }
        .gcash-info { background: #fff3cd; border: 1px solid #ffc107; border-radius: 10px; padding: 15px; margin-bottom: 20px; }
        .gcash-info .account { font-weight: 600; color: #856404; }
        .gcash-info .label { font-size: 0.75rem; color: #856404; opacity: 0.8; }
        .balance-info { background: #f8f9fa; padding: 12px 15px; border-radius: 10px; }
        .balance-info .label { font-size: 0.7rem; color: #6c757d; text-transform: uppercase; }
        @media (max-width: 576px) { .payment-body { padding: 20px; } .amount-display .amount { font-size: 2rem; } .step-connector { width: 30px; } }
    </style>
</head>
<body>
    <div class="payment-container">
        <div class="payment-card">
            <div class="payment-header">
                <i class="fab fa-gcash"></i>
                <h3>GCash Auto-Pay</h3>
                <p>Pay your contributions instantly with GCash</p>
            </div>
            
            <div class="payment-body">
                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <i class="fas fa-exclamation-circle me-2"></i>
                        <?php echo htmlspecialchars($error); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <?php if ($confirm_mode && $receipt_ref): 
                    $pending_payment = $db->getSingle(
                        "SELECT * FROM payments WHERE receipt_number = ? AND payment_status = 'pending'",
                        [$receipt_ref], 's'
                    );
                    
                    if ($pending_payment):
                ?>
                    <!-- Step 2: Confirm Payment -->
                    <div class="step-indicator">
                        <div class="step done"><div class="circle"><i class="fas fa-check"></i></div><span>Initiated</span></div>
                        <div class="step active"><div class="circle">2</div><span>Confirm</span><div class="step-connector"></div></div>
                        <div class="step"><div class="circle">3</div><span>Done</span><div class="step-connector"></div></div>
                    </div>
                    
                    <div class="gcash-logo">
                        <i class="fab fa-gcash"></i>
                        <span>GCash</span>
                        <span class="text-muted">•</span>
                        <span class="text-muted" style="font-size: 0.8rem;">Payment Confirmation</span>
                    </div>
                    
                    <div class="amount-display">
                        <div class="label">Amount to Pay</div>
                        <div class="amount">₱<span><?php echo number_format($pending_payment['amount'], 2); ?></span></div>
                    </div>
                    
                    <div class="gcash-info">
                        <div class="label">Send payment to:</div>
                        <div class="account"><i class="fas fa-phone me-2"></i><?php echo $GCASH_ACCOUNT['mobile']; ?></div>
                        <div class="account small"><i class="fas fa-user me-2"></i><?php echo $GCASH_ACCOUNT['account_name']; ?></div>
                        <div class="account small"><i class="fas fa-id-card me-2"></i>User ID: <?php echo $GCASH_ACCOUNT['user_id']; ?></div>
                    </div>
                    
                    <div class="alert alert-success">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>Open your GCash app and:</strong>
                        <ol class="mb-0 mt-2 ps-3">
                            <li>Go to <strong>"Pay"</strong> or <strong>"Send Money"</strong></li>
                            <li>Enter the GCash number: <strong><?php echo $GCASH_ACCOUNT['mobile']; ?></strong></li>
                            <li>Enter amount: <strong>₱<?php echo number_format($pending_payment['amount'], 2); ?></strong></li>
                            <li>Confirm with your MPIN or biometric</li>
                            <li>Return here and click <strong>"I've Paid"</strong></li>
                        </ol>
                    </div>
                    
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="action" value="confirm_payment">
                        <input type="hidden" name="receipt_ref" value="<?php echo $receipt_ref; ?>">
                        
                        <div class="d-grid gap-3">
                            <a href="gcash://pay?amount=<?php echo $pending_payment['amount']; ?>&ref=<?php echo $receipt_ref; ?>&receiver=<?php echo $GCASH_ACCOUNT['mobile']; ?>" 
                               class="btn-gcash-pay" 
                               onclick="event.preventDefault(); openGCashApp('<?php echo $pending_payment['amount']; ?>', '<?php echo $receipt_ref; ?>');">
                                <i class="fab fa-gcash"></i>
                                Open GCash App & Pay
                            </a>
                            
                            <button type="submit" class="btn-confirm">
                                <i class="fas fa-check-circle me-2"></i>
                                I've Paid (Confirm)
                            </button>
                            
                            <a href="payments.php" class="btn-back text-center">
                                <i class="fas fa-arrow-left me-2"></i>Cancel
                            </a>
                        </div>
                    </form>
                    
                <?php else: ?>
                    <!-- Step 1: Initiate Payment -->
                    <div class="step-indicator">
                        <div class="step active"><div class="circle">1</div><span>Initiate</span></div>
                        <div class="step"><div class="circle">2</div><span>Confirm</span><div class="step-connector"></div></div>
                        <div class="step"><div class="circle">3</div><span>Done</span><div class="step-connector"></div></div>
                    </div>
                    
                    <div class="gcash-logo">
                        <i class="fab fa-gcash"></i>
                        <span>GCash</span>
                        <span class="text-muted">•</span>
                        <span class="text-muted" style="font-size: 0.8rem;">Secure Payment</span>
                    </div>
                    
                    <div class="balance-info mb-3">
                        <div class="row">
                            <div class="col-6">
                                <div class="label">Balance Due</div>
                                <div class="fw-bold">₱<?php echo number_format($current_balance > 0 ? $current_balance : 0, 2); ?></div>
                            </div>
                            <div class="col-6 text-end">
                                <div class="label">Monthly Contribution</div>
                                <div class="fw-bold">₱<?php echo number_format($monthly_contribution, 2); ?></div>
                            </div>
                        </div>
                    </div>
                    
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="action" value="initiate_payment">
                        
                        <div class="mb-3">
                            <label class="form-label"><i class="fas fa-user me-2"></i>Member</label>
                            <input type="text" class="form-control" value="<?php echo htmlspecialchars($member_name); ?>" readonly disabled>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label"><i class="fas fa-money-bill me-2"></i>Amount to Pay (₱) <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" name="amount" step="0.01" min="1" required
                                   value="<?php echo htmlspecialchars($_POST['amount'] ?? $recommended_amount); ?>"
                                   id="amountInput">
                            <div class="mt-2">
                                <button type="button" class="btn btn-sm btn-outline-primary" onclick="setAmount(<?php echo $recommended_amount; ?>)">
                                    Suggested: ₱<?php echo number_format($recommended_amount, 2); ?>
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="setAmount(<?php echo $monthly_contribution; ?>)">
                                    Monthly: ₱<?php echo number_format($monthly_contribution, 2); ?>
                                </button>
                            </div>
                        </div>
                        
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            You will be redirected to confirm your payment. No money will be deducted until you confirm in the GCash app.
                        </div>
                        
                        <div class="d-flex gap-3">
                            <a href="payments.php" class="btn-back"><i class="fas fa-arrow-left me-2"></i>Cancel</a>
                            <button type="submit" class="btn-gcash-pay" id="initiateBtn">
                                <i class="fab fa-gcash"></i> Pay with GCash
                            </button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="text-center mt-3">
            <small class="text-muted"><i class="fas fa-lock me-1"></i>Secured by GCash • Protected by Harana</small>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function setAmount(amount) {
            document.getElementById('amountInput').value = amount;
        }
        
        function openGCashApp(amount, ref) {
            const gcashUrl = 'gcash://pay?amount=' + amount + '&ref=' + ref + '&receiver=0928867551';
            window.location.href = gcashUrl;
            
            setTimeout(function() {
                if (confirm('GCash app did not open automatically. Do you want to see manual payment instructions?')) {
                    alert('1. Open GCash App\n2. Go to "Send Money"\n3. Enter: 0928867551\n4. Amount: ₱' + amount + '\n5. Reference: ' + ref);
                }
            }, 3000);
        }
        
        document.querySelector('form')?.addEventListener('submit', function(e) {
            const btn = document.getElementById('initiateBtn');
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Processing...';
            }
        });
    </script>
</body>
</html>