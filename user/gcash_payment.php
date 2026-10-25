<?php
// user/gcash_payment.php — Realistic GCash flow (2026-10)
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

// Numeric cast — alphanumeric codes are no longer acceptable.
$member_id_int = (int)$member_code;

// ---------- Load GCash settings ----------
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

$gcash_qr_path = getAppSetting($db, 'gcash_qr_path');
$gcash_mobile  = getAppSetting($db, 'gcash_mobile', '0928867551');
$gcash_name    = getAppSetting($db, 'gcash_name',   'RO***LE');
$gcash_userid  = getAppSetting($db, 'gcash_user_id', '4LFEZX');

$has_qr = !empty($gcash_qr_path) && file_exists('../' . $gcash_qr_path);

// ---------- Balance ----------
$balance              = getUserBalance($db, $member_code);
$months_as_member     = calculateMonthsAsMember($member);
$monthly_contribution = (float)($member['monthly_contribution'] ?? 100);
$expected_total       = $months_as_member * $monthly_contribution;
$total_paid           = (float)($balance['total_paid'] ?? 0);
$current_balance      = $expected_total - $total_paid;
$recommended_amount   = $current_balance > 0 ? $current_balance : $monthly_contribution;

$error   = '';
$success = '';

// ---------- Handle submission ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_payment'])) {

    if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please refresh the page.';
    } else {
        // Amount/date are derived server-side (read-only in the UI).
        $amount   = $recommended_amount;
        $pay_date = date('Y-m-d');
        $period_month = date('Y-m'); // Explicitly set to current month for standard flow
        $notes    = trim(Security::sanitize($_POST['notes'] ?? ''));

        if ($amount <= 0) {
            $error = 'Your required amount is invalid. Please contact support.';
        } elseif (!isset($_FILES['proof']) || $_FILES['proof']['error'] !== UPLOAD_ERR_OK) {
            $error = 'Please upload a screenshot of your GCash receipt.';
        } else {

            $file     = $_FILES['proof'];
            $allowed  = ['image/jpeg', 'image/png', 'image/webp'];
            $max_size = 5 * 1024 * 1024;

            if (!in_array($file['type'], $allowed, true)) {
                $error = 'Proof must be a JPG, PNG, or WEBP image.';
            } elseif ($file['size'] > $max_size) {
                $error = 'Screenshot must be less than 5MB.';
            } else {

                // Save screenshot
                $upload_dir = '../uploads/payment_proofs/';
                if (!is_dir($upload_dir)) {
                    @mkdir($upload_dir, 0755, true);
                }

                $ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                $filename = 'gcash_' . $member_code . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                $filepath = $upload_dir . $filename;

                if (!move_uploaded_file($file['tmp_name'], $filepath)) {
                    $error = 'Failed to save your screenshot. Please try again.';
                } else {

                    require_once __DIR__ . '/../includes/receipts.php';
                    $receipt_number = generateReceiptNumber($db);
                    $payment_uuid   = bin2hex(random_bytes(16));
                    $proof_path     = 'uploads/payment_proofs/' . $filename;
                    $auto_ref_no    = 'REQ-' . strtoupper(substr($payment_uuid, 0, 8));

                    // ── INSERT with explicit period_month ─────────────
                    $sql = "INSERT INTO payments (
                                payment_uuid, member_id, member_code,
                                payment_date, period_month, amount,
                                payment_method, gcash_reference, payment_status,
                                receipt_number, notes, ip_address,
                                proof_of_payment, created_at
                            ) VALUES (?, ?, ?, ?, ?, ?, 'gcash', ?, 'pending', ?, ?, ?, ?, NOW())";

                    // Types: s-i-s-s-s-d-s-s-s-s-s = 11 params
                    $ok = $db->execute(
                        $sql,
                        [
                            $payment_uuid,
                            $member_id_int,
                            $member_code,
                            $pay_date,
                            $period_month,
                            $amount,
                            $auto_ref_no,
                            $receipt_number,
                            $notes,
                            Security::getClientIP(),
                            $proof_path,
                        ],
                        'sissdssssss'
                    );

                    if ($ok) {
                        Security::logEvent('PAYMENT_GCASH_SUBMITTED',
                            "GCash payment submitted: $receipt_number | Auto-Ref: $auto_ref_no | Member: $member_code | Amount: $amount | Period: $period_month");

                        // Notify admins
                        $admins = $db->getAll("SELECT user_id FROM users WHERE role = 'admin' AND is_active = 1");
                        foreach ($admins as $adm) {
                            if (function_exists('createNotification')) {
                                createNotification(
                                    $db, $adm['user_id'],
                                    "New GCash Payment Pending",
                                    "$member_name submitted ₱" . number_format($amount, 2) .
                                    " via GCash for " . date('F Y', strtotime($period_month . '-01')) .
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
<title>Pay via GCash — <?php echo defined('APP_NAME') ? APP_NAME : 'NHGL'; ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
/* ============================================================
   TOKENS (Matches Dashboard/Profile)
============================================================ */
:root{
    --brand-900:#1e3a5a;--brand-800:#162d47;--brand-700:#234c76;--brand-600:#2d5f92;
    --brand-500:#3d7ab8;--brand-100:#e8f0f8;--brand-50:#f4f8fc;
    --yellow-accent:#fbbf24;--yellow-dark:#b45309;--yellow-100:#fef3c7;
    --success-700:#14532d;--success-500:#16a34a;--success-100:#dcfce7;
    --danger-700:#7f1d1d;--danger-500:#dc2626;--danger-100:#fee2e2;
    --warning-700:#78350f;--warning-500:#d97706;--warning-100:#fef3c7;
    --ink-900:#1e2b3c;--ink-700:#334155;--ink-500:#64748b;--ink-400:#94a3b8;--ink-300:#cbd5e1;
    --line:#e2e8f0;--line-soft:#eef2f7;--paper:#f4f7fc;--surface:#ffffff;
    --radius-sm:10px;--radius:14px;--radius-lg:18px;
    --shadow-sm:0 2px 8px rgba(16,24,40,0.07);
    --shadow-md:0 4px 14px rgba(16,24,40,0.08);
    --shadow-lg:0 10px 26px rgba(16,24,40,0.10);
}
*{box-sizing:border-box}
html{font-size:18px}
body{
    background:var(--paper);
    font-family:'Inter',-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;
    font-size:1rem;line-height:1.6;color:var(--ink-900);
    margin:0;padding:0;min-height:100vh;
    -webkit-font-smoothing:antialiased;
}

/* ============================================================
   APP HEADER
============================================================ */
.app-header{
    background:var(--surface);border-bottom:1px solid var(--line);
    padding:0.85rem 1rem;display:flex;align-items:center;justify-content:space-between;
    gap:12px;position:sticky;top:0;z-index:50;box-shadow:var(--shadow-sm);
}
.app-brand{display:flex;align-items:center;gap:10px;min-width:0}
.app-brand .logo-wrap{
    width:44px;height:44px;border-radius:10px;background:var(--brand-900);color:#fff;
    display:flex;align-items:center;justify-content:center;font-weight:900;font-size:1rem;flex-shrink:0;overflow:hidden;
}
.app-brand .logo-wrap img{width:100%;height:100%;object-fit:contain;background:#fff;padding:4px}
.app-brand .brand-text{display:flex;flex-direction:column;line-height:1.15;min-width:0}
.app-brand .brand-name{font-weight:800;font-size:1rem;color:var(--ink-900)}
.app-brand .brand-sub{font-size:0.7rem;color:var(--ink-500);font-weight:500;line-height:1.2}
.header-actions{display:flex;gap:8px;flex-shrink:0}
.icon-btn{
    width:44px;height:44px;border-radius:10px;background:var(--paper);border:1px solid var(--line);
    color:var(--ink-700);display:inline-flex;align-items:center;justify-content:center;
    cursor:pointer;transition:all 0.15s;text-decoration:none;
}
.icon-btn:hover{background:var(--brand-50);border-color:var(--brand-500);color:var(--brand-700)}
.icon-btn svg{width:20px;height:20px}

/* ============================================================
   MAIN LAYOUT
============================================================ */
.main{max-width:640px;margin:0 auto;padding:1.25rem 1rem 6rem}

/* Alerts */
.alert{
    border-radius:var(--radius);padding:0.85rem 1rem;margin-bottom:0.9rem;font-weight:600;
    display:flex;align-items:flex-start;gap:10px;font-size:0.9rem;line-height:1.5;
}
.alert.alert-danger{background:var(--danger-100);border:2px solid #fca5a5;color:var(--danger-700)}
.alert svg{width:20px;height:20px;flex-shrink:0;margin-top:1px}

/* Cards */
.card{
    background:var(--surface);border:1px solid var(--line);border-radius:var(--radius-lg);
    overflow:hidden;box-shadow:var(--shadow-sm);margin-bottom:1rem;
}
.card-header{
    background:var(--brand-50);border-bottom:1px solid var(--line);padding:0.85rem 1rem;
    display:flex;align-items:center;gap:10px;
}
.card-header h2{font-size:0.95rem;font-weight:800;color:var(--ink-900);margin:0;display:flex;align-items:center;gap:8px}
.card-header h2 svg{color:var(--brand-600);width:18px;height:18px}
.card-body{padding:1rem}

/* Warning Box */
.warning-box{
    background:var(--danger-100);border-left:5px solid var(--danger-500);
    border-radius:var(--radius-sm);padding:1rem;margin-bottom:1.5rem;
    color:var(--danger-700);
}
.warning-box h3{font-size:0.95rem;font-weight:800;margin:0 0 4px;display:flex;align-items:center;gap:6px}
.warning-box p{font-size:0.85rem;margin:0;line-height:1.5}

/* QR Block */
.qr-wrap{text-align:center;padding:1rem 0}
.qr-frame{
    display:inline-block;padding:12px;background:#fff;border:2px solid var(--line);
    border-radius:var(--radius);box-shadow:var(--shadow-sm);margin-bottom:1rem;
}
.qr-frame img{display:block;width:280px;height:280px;object-fit:contain}
.btn-download{
    display:inline-flex;align-items:center;gap:6px;background:var(--brand-50);
    color:var(--brand-700);border:1px solid var(--brand-500);padding:8px 16px;
    border-radius:100px;font-size:0.85rem;font-weight:700;text-decoration:none;
    margin-bottom:1.5rem;transition:all 0.15s;
}
.btn-download:hover{background:var(--brand-100);color:var(--brand-800)}
.no-qr{
    padding:2rem;background:var(--paper);border:2px dashed var(--line);border-radius:var(--radius-sm);
    color:var(--ink-500);font-size:0.9rem;
}

/* Account Details */
.acct-details{
    background:var(--brand-50);border:1px solid var(--line);border-radius:var(--radius-sm);
    padding:1rem;text-align:left;margin-bottom:1rem;
}
.acct-row{display:flex;justify-content:space-between;margin-bottom:6px;font-size:0.9rem}
.acct-row:last-child{margin-bottom:0}
.acct-row .lbl{color:var(--ink-500);font-weight:600}
.acct-row .val{font-weight:800;color:var(--ink-900)}

/* Steps */
.steps-box{
    background:var(--paper);border-radius:var(--radius-sm);padding:1rem;margin-top:1rem;
}
.steps-box ol{margin:0;padding-left:1.2rem;font-size:0.85rem;color:var(--ink-700);line-height:1.6}
.steps-box li{margin-bottom:6px}
.steps-box li:last-child{margin-bottom:0}
.steps-box strong{color:var(--ink-900)}

/* Form */
.form-group{margin-bottom:1rem}
.form-label{display:block;font-size:0.8rem;font-weight:800;color:var(--ink-500);text-transform:uppercase;margin-bottom:6px}
.form-control{
    width:100%;border:1px solid var(--line);border-radius:var(--radius-sm);
    padding:0.75rem 1rem;font-size:1rem;font-family:inherit;background:var(--surface);
    color:var(--ink-900);outline:none;transition:all 0.15s;
}
.form-control:focus{border-color:var(--brand-500);box-shadow:0 0 0 3px rgba(61,122,184,0.15)}
.form-control[readonly]{
    background:var(--paper);color:var(--ink-500);cursor:not-allowed;border-color:var(--line-soft);
}
.input-group{position:relative}
.input-group .currency{
    position:absolute;left:1rem;top:50%;transform:translateY(-50%);
    font-weight:800;color:var(--ink-500);font-size:1.1rem;pointer-events:none;
}
.input-group .form-control{padding-left:2.2rem;font-weight:800;font-size:1.1rem}

/* Upload Zone */
.upload-zone{
    border:2px dashed var(--line);border-radius:var(--radius-sm);
    padding:1.5rem 1rem;text-align:center;cursor:pointer;
    transition:all 0.15s;background:var(--paper);display:block;
}
.upload-zone:hover{border-color:var(--brand-500);background:var(--brand-50)}
.upload-zone.has-file{border-color:var(--success-500);background:var(--success-100);border-style:solid}
.upload-zone svg{width:24px;height:24px;color:var(--ink-400);margin-bottom:6px}
.upload-zone .u-text{font-size:0.9rem;font-weight:700;color:var(--ink-900)}
.upload-zone .u-sub{font-size:0.75rem;color:var(--ink-500);margin-top:2px}
.upload-zone input{display:none}

#preview{display:none;margin-top:1rem;text-align:center}
#preview img{max-width:100%;max-height:200px;border-radius:var(--radius-sm);border:1px solid var(--line)}

/* Buttons */
.btn-submit{
    background:var(--yellow-accent);color:var(--brand-900);border:none;
    padding:1rem 1.25rem;border-radius:var(--radius);font-size:1rem;font-weight:800;
    font-family:inherit;cursor:pointer;transition:all 0.18s;
    display:inline-flex;align-items:center;justify-content:center;gap:10px;
    width:100%;min-height:56px;box-shadow:0 4px 14px rgba(251,191,36,0.35);margin-top:1rem;
}
.btn-submit:hover:not(:disabled){transform:translateY(-2px);box-shadow:0 8px 20px rgba(251,191,36,0.5)}
.btn-submit:disabled{opacity:0.6;cursor:not-allowed}
.btn-outline{
    background:transparent;color:var(--brand-600);border:1.5px solid var(--line);
    padding:1rem 1.25rem;border-radius:var(--radius);font-size:0.95rem;font-weight:700;
    font-family:inherit;cursor:pointer;transition:all 0.15s;text-decoration:none;
    display:inline-flex;align-items:center;justify-content:center;gap:10px;
    width:100%;min-height:56px;margin-top:0.5rem;
}
.btn-outline:hover{background:var(--brand-50);border-color:var(--brand-600)}

/* Bottom Nav */
.bottom-nav{
    position:fixed;bottom:0;left:0;right:0;background:var(--surface);border-top:1px solid var(--line);
    display:flex;z-index:60;box-shadow:0 -4px 14px rgba(16,24,40,0.06);
}
.bottom-nav .nav-item{
    flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:3px;
    padding:10px 4px 12px;text-decoration:none;color:var(--ink-500);
    font-size:0.72rem;font-weight:600;transition:color 0.15s;min-height:64px;
}
.bottom-nav .nav-item.active{color:var(--brand-700)}
.bottom-nav .nav-item .icon{font-size:1.35rem;line-height:1;font-weight:900}

@media (min-width:700px){
    .main{padding-bottom:2rem}
    .bottom-nav{position:static;max-width:640px;margin:0 auto 2rem;border-radius:var(--radius);border:1px solid var(--line);box-shadow:var(--shadow-sm)}
}
@media (max-width:540px){
    html{font-size:17px}
    .main{padding:0.85rem 0.75rem 6rem}
    .app-brand .brand-sub{display:none}
    .qr-frame img{width:240px;height:240px}
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
            <span class="brand-name">NHGL</span>
            <span class="brand-sub">Nagkaisang Haranista</span>
        </div>
    </div>
    <div class="header-actions">
        <a href="payments.php" class="icon-btn" aria-label="Back">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
        </a>
    </div>
</header>

<main class="main">

    <?php if ($error): ?>
        <div class="alert alert-danger">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <span><?php echo htmlspecialchars($error); ?></span>
        </div>
    <?php endif; ?>

    <!-- Step 1: Scan -->
    <div class="card">
        <div class="card-header">
            <h2><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><path d="M7 7h.01M7 12h.01M7 17h.01M12 7h.01M12 12h.01M12 17h.01M17 7h.01M17 12h.01M17 17h.01"/></svg> Step 1 — Scan or Download QR</h2>
        </div>
        <div class="card-body">
            <div class="qr-wrap">
                <?php if ($has_qr): ?>
                    <div class="qr-frame">
                        <img src="../<?php echo htmlspecialchars($gcash_qr_path); ?>?v=<?php echo time(); ?>" alt="GCash QR Code">
                    </div>
                    <br>
                    <a href="../<?php echo htmlspecialchars($gcash_qr_path); ?>" download="NHGL_GCash_QR.jpg" class="btn-download">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="width:16px;height:16px;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                        Download QR Code
                    </a>
                <?php else: ?>
                    <div class="no-qr">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><path d="M7 7h.01M7 12h.01M7 17h.01M12 7h.01M12 12h.01M12 17h.01M17 7h.01M17 12h.01M17 17h.01"/></svg><br>
                        <strong>QR code not yet available.</strong><br>
                        Please contact the administrator.
                    </div>
                <?php endif; ?>

                <div class="acct-details">
                    <div class="acct-row">
                        <span class="lbl">GCash Number</span>
                        <span class="val"><?php echo htmlspecialchars($gcash_mobile); ?></span>
                    </div>
                    <div class="acct-row">
                        <span class="lbl">Account Name</span>
                        <span class="val"><?php echo htmlspecialchars($gcash_name); ?></span>
                    </div>
                    <?php if ($gcash_userid): ?>
                    <div class="acct-row">
                        <span class="lbl">User ID</span>
                        <span class="val"><?php echo htmlspecialchars($gcash_userid); ?></span>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="steps-box">
                    <ol>
                        <li>Tap the <strong>Download QR Code</strong> button above (or take a screenshot).</li>
                        <li>Open your <strong>GCash app</strong>.</li>
                        <li>Tap <strong>QR</strong> at the top, then <strong>Upload QR</strong> and select the image you saved.</li>
                        <li>Enter your <strong>exact monthly contribution</strong> and confirm with your MPIN.</li>
                        <li>Once done, <strong>download or screenshot your GCash receipt</strong>.</li>
                        <li>Come back to this page, upload your receipt below, and submit.</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>

    <!-- Warning Box -->
    <div class="warning-box">
        <h3>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="width:18px;height:18px;"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
            Important Reminder
        </h3>
        <p>Please send the exact amount for your contribution. If you send an insufficient amount your payment will be voided.</p>
    </div>

    <!-- Step 2: Confirm -->
    <div class="card">
        <div class="card-header">
            <h2><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg> Step 2 — Upload Receipt</h2>
        </div>
        <div class="card-body">
            <?php if (!$has_qr): ?>
                <div class="alert alert-danger" style="margin:0;">
                    Submissions are disabled until the administrator uploads the GCash QR code.
                </div>
            <?php else: ?>
            <form method="POST" enctype="multipart/form-data" id="gcashForm">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="submit_payment" value="1">

                <div class="form-group">
                    <label class="form-label">Amount to be sent</label>
                    <div class="input-group">
                        <span class="currency">₱</span>
                        <input type="number" class="form-control" value="<?php echo htmlspecialchars(number_format($recommended_amount, 2, '.', '')); ?>" readonly>
                    </div>
                    <span style="font-size:0.75rem;color:var(--ink-500);display:block;margin-top:4px;">This is fixed based on your monthly contribution.</span>
                </div>

                <div class="form-group">
                    <label class="form-label">Payment Date</label>
                    <input type="date" class="form-control" value="<?php echo date('Y-m-d'); ?>" readonly>
                </div>

                <div class="form-group">
                    <label class="form-label">Upload GCash Receipt</label>
                    <label class="upload-zone" id="uploadZone" for="proofInput">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                        <div class="u-text" id="uploadText">Tap to upload screenshot</div>
                        <div class="u-sub">JPG, PNG, or WEBP (Max 5MB)</div>
                        <input type="file" name="proof" id="proofInput" accept="image/jpeg,image/png,image/webp" required>
                    </label>
                    <div id="preview">
                        <img id="previewImg" src="" alt="Receipt preview">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Notes (Optional)</label>
                    <textarea name="notes" class="form-control" rows="2" placeholder="e.g. Payment for Jan-Mar 2026"></textarea>
                </div>

                <button type="submit" class="btn-submit" id="submitBtn">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="width:18px;height:18px;"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                    Submit Payment
                </button>

                <a href="payments.php" class="btn-outline">
                    Cancel
                </a>
            </form>
            <?php endif; ?>
        </div>
    </div>

</main>

<nav class="bottom-nav">
    <a href="dashboard.php" class="nav-item">
        <span class="icon">🏠</span><span>Home</span>
    </a>
    <a href="payments.php" class="nav-item active">
        <span class="icon">💳</span><span>Payments</span>
    </a>
    <a href="profile.php" class="nav-item">
        <span class="icon">👤</span><span>Profile</span>
    </a>
    <a href="support.php" class="nav-item">
        <span class="icon">❓</span><span>Help</span>
    </a>
</nav>

<script>
(function () {
    const input   = document.getElementById('proofInput');
    const zone    = document.getElementById('uploadZone');
    const txt     = document.getElementById('uploadText');
    const preview = document.getElementById('preview');
    const img     = document.getElementById('previewImg');
    const form    = document.getElementById('gcashForm');
    const btn     = document.getElementById('submitBtn');

    input?.addEventListener('change', function () {
        const file = this.files && this.files[0];
        if (!file) return;
        if (file.size > 5 * 1024 * 1024) {
            alert('File is larger than 5MB.');
            this.value = '';
            return;
        }
        txt.textContent = file.name;
        zone.classList.add('has-file');
        const reader = new FileReader();
        reader.onload = e => { img.src = e.target.result; preview.style.display = 'block'; };
        reader.readAsDataURL(file);
    });

    form?.addEventListener('submit', function () {
        btn.disabled = true;
        btn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="width:18px;height:18px;" class="spin"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg> Submitting...';
    });
})();
</script>

</body>
</html>