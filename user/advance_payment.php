<?php
// user/advance_payment.php — Pay in Advance Flow
// Refactored for strict data integrity.

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
$monthly_contribution = (float)($member['monthly_contribution'] ?? 100);

// Numeric cast — alphanumeric codes are no longer acceptable.
$member_id_int = (int)$member_code;

// Fetch GCash settings
if (!function_exists('getAppSetting')) {
    function getAppSetting($db, $key, $default = null) {
        try {
            $row = $db->getSingle("SELECT setting_value FROM app_settings WHERE setting_key = ?", [$key], 's');
            return $row['setting_value'] ?? $default;
        } catch (Throwable $e) { return $default; }
    }
}

$gcash_qr_path = getAppSetting($db, 'gcash_qr_path');
$gcash_mobile  = getAppSetting($db, 'gcash_mobile', '0928867551');
$gcash_name    = getAppSetting($db, 'gcash_name',   'RO***LE');
$gcash_userid  = getAppSetting($db, 'gcash_user_id', '4LFEZX');
$has_qr = !empty($gcash_qr_path) && file_exists('../' . $gcash_qr_path);

// Get currently selected months from URL (default to 1)
$selected_months = isset($_GET['months']) ? (int)$_GET['months'] : 1;
if ($selected_months < 1) $selected_months = 1;
if ($selected_months > 12) $selected_months = 12; // Max 12 months advance

$total_amount = $selected_months * $monthly_contribution;

$error = '';
$success = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_advance'])) {
    if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please refresh the page.';
    } else {
        $months_to_pay = (int)$_POST['months_to_pay'];
        $amount = $months_to_pay * $monthly_contribution;

        if (!isset($_FILES['proof']) || $_FILES['proof']['error'] !== UPLOAD_ERR_OK) {
            $error = 'Please upload a screenshot of your GCash receipt.';
        } else {
            $file = $_FILES['proof'];
            $allowed = ['image/jpeg', 'image/png', 'image/webp'];

            if (!in_array($file['type'], $allowed, true)) {
                $error = 'Proof must be a JPG, PNG, or WEBP image.';
            } else {
                $upload_dir = '../uploads/payment_proofs/';
                if (!is_dir($upload_dir)) @mkdir($upload_dir, 0755, true);

                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                $filename = 'advance_' . $member_code . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                $filepath = $upload_dir . $filename;

                if (move_uploaded_file($file['tmp_name'], $filepath)) {
                    require_once __DIR__ . '/../includes/receipts.php';
                    $receipt_number = generateReceiptNumber($db);
                    $payment_uuid   = bin2hex(random_bytes(16));
                    $proof_path     = 'uploads/payment_proofs/' . $filename;
                    $auto_ref_no    = 'ADV-' . strtoupper(substr($payment_uuid, 0, 8));
                    $pay_date       = date('Y-m-d');
                    $notes          = "Advance payment for $months_to_pay months.";

                    // ── INSERT with BOTH identity columns ─────────────
                    // member_id   : INT (direct cast, numeric only)
                    // member_code : VARCHAR (canonical truth)
                    $sql = "INSERT INTO payments (
                                payment_uuid, member_id, member_code,
                                payment_date, amount,
                                payment_method, gcash_reference, payment_status,
                                receipt_number, notes, ip_address,
                                proof_of_payment, created_at
                            ) VALUES (?, ?, ?, ?, ?, 'gcash', ?, 'pending', ?, ?, ?, ?, NOW())";

                    // Type string: s-i-s-s-d-s-s-s-s-s = 10 params
                    $ok = $db->execute($sql, [
                        $payment_uuid,
                        $member_id_int,
                        $member_code,
                        $pay_date,
                        $amount,
                        $auto_ref_no,
                        $receipt_number,
                        $notes,
                        Security::getClientIP(),
                        $proof_path,
                    ], 'sissdsssss');

                    if ($ok) {
                        Security::logEvent('PAYMENT_ADVANCE_SUBMITTED',
                            "Advance GCash payment submitted: $receipt_number | Auto-Ref: $auto_ref_no | Member: $member_code | Months: $months_to_pay | Amount: $amount");

                        // Notify admins — parity with gcash_payment.php
                        $admins = $db->getAll("SELECT user_id FROM users WHERE role = 'admin' AND is_active = 1");
                        foreach ($admins as $adm) {
                            if (function_exists('createNotification')) {
                                createNotification(
                                    $db, $adm['user_id'],
                                    "New GCash Advance Payment Pending",
                                    "$member_name submitted an advance payment of ₱" . number_format($amount, 2) .
                                    " for $months_to_pay month" . ($months_to_pay === 1 ? '' : 's') .
                                    ". Please review the receipt screenshot.",
                                    'payment',
                                    '../admin/payments.php?status=pending',
                                    'credit-card'
                                );
                            }
                        }

                        header('Location: payments.php?success=gcash_submitted');
                        exit();
                    } else {
                        @unlink($filepath);
                        $error = 'Database error. Please try again.';
                    }
                } else {
                    $error = 'Failed to save screenshot.';
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
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=2.0, user-scalable=yes">
<title>Pay in Advance — <?php echo defined('APP_NAME') ? APP_NAME : 'NHGL'; ?></title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
:root{
    --brand-900:#1e3a5a;--brand-800:#162d47;--brand-700:#234c76;--brand-600:#2d5f92;
    --brand-500:#3d7ab8;--brand-100:#e8f0f8;--brand-50:#f4f8fc;
    --yellow-accent:#fbbf24;--yellow-dark:#b45309;--yellow-100:#fef3c7;
    --success-700:#14532d;--success-500:#16a34a;--success-100:#dcfce7;
    --danger-700:#7f1d1d;--danger-500:#dc2626;--danger-100:#fee2e2;
    --warning-700:#78350f;--warning-500:#d97706;--warning-100:#fef3c7;
    --ink-900:#1e2b3c;--ink-700:#334155;--ink-500:#64748b;--ink-400:#94a3b8;
    --line:#e2e8f0;--line-soft:#eef2f7;--paper:#f4f7fc;--surface:#ffffff;
    --radius-sm:10px;--radius:14px;--radius-lg:18px;
    --shadow-sm:0 2px 8px rgba(16,24,40,0.07);
    --shadow-md:0 4px 14px rgba(16,24,40,0.08);
}
*{box-sizing:border-box}
html{font-size:18px}
body{background:var(--paper);font-family:'Inter',sans-serif;font-size:1rem;line-height:1.6;color:var(--ink-900);margin:0;padding:0;min-height:100vh;}

/* Header */
.app-header{background:var(--surface);border-bottom:1px solid var(--line);padding:0.85rem 1rem;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:50;}
.app-brand{display:flex;align-items:center;gap:10px;}
.app-brand .logo-wrap{width:44px;height:44px;border-radius:10px;background:var(--brand-900);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:900;overflow:hidden;}
.app-brand .logo-wrap img{width:100%;height:100%;object-fit:contain;background:#fff;padding:4px}
.app-brand .brand-text{display:flex;flex-direction:column;line-height:1.15;}
.app-brand .brand-name{font-weight:800;font-size:1rem;}
.icon-btn{width:44px;height:44px;border-radius:10px;background:var(--paper);border:1px solid var(--line);color:var(--ink-700);display:inline-flex;align-items:center;justify-content:center;text-decoration:none;}
.icon-btn svg{width:20px;height:20px}

.main{max-width:640px;margin:0 auto;padding:1.25rem 1rem 6rem}

/* Cards */
.card{background:var(--surface);border:1px solid var(--line);border-radius:var(--radius-lg);overflow:hidden;box-shadow:var(--shadow-sm);margin-bottom:1rem;}
.card-header{background:var(--brand-50);border-bottom:1px solid var(--line);padding:0.85rem 1rem;display:flex;align-items:center;gap:10px;}
.card-header h2{font-size:0.95rem;font-weight:800;color:var(--ink-900);margin:0;}
.card-body{padding:1rem;}

/* Tabs for selecting months */
.months-grid {display:grid;grid-template-columns:repeat(4, 1fr);gap:8px;margin-bottom:1.5rem;}
.month-btn {
    background:var(--surface);border:1.5px solid var(--line);border-radius:var(--radius-sm);
    padding:12px 4px;text-align:center;text-decoration:none;color:var(--ink-700);
    font-weight:700;font-size:0.9rem;transition:all 0.15s;
}
.month-btn:hover {border-color:var(--brand-500);background:var(--brand-50);}
.month-btn.active {border-color:var(--brand-900);background:var(--brand-900);color:#fff;}

/* Amount Display */
.amount-display {
    background:var(--brand-50);border:2px dashed var(--brand-500);border-radius:var(--radius);
    padding:1.5rem;text-align:center;margin-bottom:1.5rem;
}
.amount-display .lbl {font-size:0.85rem;font-weight:800;color:var(--brand-700);text-transform:uppercase;letter-spacing:0.05em;margin-bottom:6px;}
.amount-display .val {font-size:2.5rem;font-weight:900;color:var(--brand-900);line-height:1;}

/* QR & Upload */
.qr-wrap{text-align:center;padding:1rem 0}
.qr-frame{display:inline-block;padding:12px;background:#fff;border:2px solid var(--line);border-radius:var(--radius);margin-bottom:1rem;}
.qr-frame img{display:block;width:260px;height:260px;object-fit:contain}
.acct-details{background:var(--brand-50);border:1px solid var(--line);border-radius:var(--radius-sm);padding:1rem;text-align:left;margin-bottom:1rem;}
.acct-row{display:flex;justify-content:space-between;margin-bottom:6px;font-size:0.9rem}
.acct-row:last-child{margin-bottom:0}
.acct-row .lbl{color:var(--ink-500);font-weight:600}
.acct-row .val{font-weight:800;color:var(--ink-900)}

/* Form */
.form-group{margin-bottom:1rem}
.form-label{display:block;font-size:0.8rem;font-weight:800;color:var(--ink-500);text-transform:uppercase;margin-bottom:6px}
.upload-zone{border:2px dashed var(--line);border-radius:var(--radius-sm);padding:1.5rem 1rem;text-align:center;cursor:pointer;transition:all 0.15s;background:var(--paper);display:block;}
.upload-zone:hover{border-color:var(--brand-500);background:var(--brand-50)}
.upload-zone.has-file{border-color:var(--success-500);background:var(--success-100);border-style:solid}
.upload-zone svg{width:24px;height:24px;color:var(--ink-400);margin-bottom:6px}
.upload-zone .u-text{font-size:0.9rem;font-weight:700;color:var(--ink-900)}
.upload-zone input{display:none}

.btn-submit{background:var(--yellow-accent);color:var(--brand-900);border:none;padding:1rem 1.25rem;border-radius:var(--radius);font-size:1rem;font-weight:800;cursor:pointer;width:100%;min-height:56px;margin-top:1rem; box-shadow:0 4px 14px rgba(251,191,36,0.35);}
.btn-submit:hover{transform:translateY(-2px);box-shadow:0 8px 20px rgba(251,191,36,0.5);}
.btn-outline{background:transparent;color:var(--brand-600);border:1.5px solid var(--line);padding:1rem 1.25rem;border-radius:var(--radius);font-size:0.95rem;font-weight:700;text-decoration:none;display:inline-flex;justify-content:center;width:100%;margin-top:0.5rem;}

.alert{border-radius:var(--radius);padding:0.85rem 1rem;margin-bottom:0.9rem;font-weight:600;font-size:0.9rem;background:var(--danger-100);color:var(--danger-700);border:2px solid #fca5a5;}

@media (max-width:540px){
    .months-grid {grid-template-columns:repeat(3, 1fr);}
}
</style>
</head>
<body>

<header class="app-header">
    <div class="app-brand">
        <div class="logo-wrap">
            <img src="../assets/images/harana-logo.png" alt="NHGL" onerror="this.style.display='none';this.parentNode.textContent='NH';">
        </div>
        <div class="brand-text">
            <span class="brand-name">Advance Payment</span>
        </div>
    </div>
    <a href="dashboard.php" class="icon-btn" aria-label="Back">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
    </a>
</header>

<main class="main">

    <?php if ($error): ?>
        <div class="alert"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <!-- STEP 1: Select Months -->
    <div class="card">
        <div class="card-header">
            <h2>Step 1 — Select Months to Pay</h2>
        </div>
        <div class="card-body">
            <p style="font-size:0.9rem;color:var(--ink-500);margin-top:0;margin-bottom:1rem;">
                Choose how many months you want to pay in advance.
            </p>

            <div class="months-grid">
                <?php for($i=1; $i<=12; $i++): ?>
                    <a href="?months=<?php echo $i; ?>" class="month-btn <?php echo $selected_months == $i ? 'active' : ''; ?>">
                        <?php echo $i; ?> Mo<?php echo $i > 1 ? 's' : ''; ?>
                    </a>
                <?php endfor; ?>
            </div>

            <div class="amount-display">
                <div class="lbl">Total Amount to Pay</div>
                <div class="val">₱<?php echo number_format($total_amount, 2); ?></div>
            </div>
        </div>
    </div>

    <!-- STEP 2: Pay via GCash -->
    <div class="card">
        <div class="card-header">
            <h2>Step 2 — Scan GCash QR</h2>
        </div>
        <div class="card-body">
            <div class="qr-wrap">
                <?php if ($has_qr): ?>
                    <div class="qr-frame">
                        <img src="../<?php echo htmlspecialchars($gcash_qr_path); ?>?v=<?php echo time(); ?>" alt="GCash QR">
                    </div>
                    <div class="acct-details">
                        <div class="acct-row"><span class="lbl">Number</span><span class="val"><?php echo htmlspecialchars($gcash_mobile); ?></span></div>
                        <div class="acct-row"><span class="lbl">Name</span><span class="val"><?php echo htmlspecialchars($gcash_name); ?></span></div>
                    </div>
                <?php else: ?>
                    <div class="alert">GCash QR is not available. Contact admin.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- STEP 3: Upload Receipt -->
    <div class="card">
        <div class="card-header">
            <h2>Step 3 — Upload Receipt</h2>
        </div>
        <div class="card-body">
            <?php if (!$has_qr): ?>
                <div class="alert">Submissions disabled until QR is uploaded.</div>
            <?php else: ?>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="months_to_pay" value="<?php echo $selected_months; ?>">

                <div class="form-group">
                    <label class="form-label">Upload GCash Screenshot</label>
                    <label class="upload-zone" id="uploadZone" for="proofInput">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                        <div class="u-text" id="uploadText">Tap to upload screenshot</div>
                        <div class="u-sub">JPG, PNG (Max 5MB)</div>
                        <input type="file" name="proof" id="proofInput" accept="image/*" required>
                    </label>
                </div>

                <button type="submit" name="submit_advance" class="btn-submit">
                    Submit Advance Payment
                </button>
                <a href="dashboard.php" class="btn-outline">Cancel</a>
            </form>
            <?php endif; ?>
        </div>
    </div>

</main>

<script>
document.getElementById('proofInput')?.addEventListener('change', function() {
    if (this.files.length > 0) {
        document.getElementById('uploadText').textContent = this.files[0].name;
        document.getElementById('uploadZone').classList.add('has-file');
    }
});
</script>
</body>
</html>