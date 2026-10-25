<?php
// user/beneficiary.php — Phone-first redesign (2026-10)
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
$message = '';
$error = '';

// Calculate membership duration
$date_joined = $member['date_joined'] ?? date('Y-m-d');
$join_date = new DateTime($date_joined);
$today = new DateTime();
$years_as_member = $join_date->diff($today)->y;

function calculateDeathBenefit($years) {
    $benefit_table = [
        1 => 80000, 2 => 80000, 3 => 90000, 4 => 90000,
        5 => 100000, 6 => 100000, 7 => 110000, 8 => 110000,
        9 => 115000, 10 => 120000, 11 => 125000, 12 => 130000,
        13 => 135000, 14 => 140000, 15 => 145000, 16 => 150000
    ];
    if ($years >= 16) return 150000;
    if ($years >= 1 && $years <= 16) return $benefit_table[$years] ?? 80000;
    return 0;
}

$base_benefit = calculateDeathBenefit($years_as_member);
$accident_bonus = 10000;
$good_payer_bonus = 5000;
$total_benefit = $base_benefit + $accident_bonus + $good_payer_bonus;

$pending_request = $db->getSingle(
    "SELECT * FROM beneficiary_requests WHERE member_code = ? AND status = 'pending' ORDER BY created_at DESC LIMIT 1",
    [$member_code], 's'
);

// Handle submit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_beneficiary'])) {
    if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token';
    } else {
        $beneficiary_name = Security::sanitize($_POST['beneficiary_name'] ?? '');
        $beneficiary_address = Security::sanitize($_POST['beneficiary_address'] ?? '');
        $beneficiary_relation = Security::sanitize($_POST['beneficiary_relation'] ?? '');
        $beneficiary_age = !empty($_POST['beneficiary_age']) ? intval($_POST['beneficiary_age']) : null;
        $beneficiary_contact = Security::sanitize($_POST['beneficiary_contact'] ?? '');
        
        if (empty($beneficiary_name) || empty($beneficiary_address) || empty($beneficiary_relation)) {
            $error = 'Please fill in all required fields (Name, Address, Relationship)';
        } elseif ($pending_request) {
            $error = 'You already have a pending beneficiary update request. Please wait for approval.';
        } else {
            $uploaded_files = [];
            $upload_dir = '../uploads/beneficiary/';
            if (!file_exists($upload_dir)) { mkdir($upload_dir, 0777, true); }
            
            if (!empty($_FILES['documents']['name'][0])) {
                $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'application/pdf'];
                $max_size = 5 * 1024 * 1024;
                
                foreach ($_FILES['documents']['tmp_name'] as $key => $tmp_name) {
                    if ($_FILES['documents']['error'][$key] === UPLOAD_ERR_OK) {
                        $file_name = $_FILES['documents']['name'][$key];
                        $file_type = $_FILES['documents']['type'][$key];
                        $file_size = $_FILES['documents']['size'][$key];
                        
                        if (!in_array($file_type, $allowed_types)) { $error = 'Invalid file type.'; break; }
                        if ($file_size > $max_size) { $error = 'File size must be less than 5MB'; break; }
                        
                        $new_filename = $member_code . '_' . time() . '_' . $key . '_' . preg_replace('/[^a-zA-Z0-9\.]/', '_', $file_name);
                        if (move_uploaded_file($tmp_name, $upload_dir . $new_filename)) {
                            $uploaded_files[] = $new_filename;
                        }
                    }
                }
            }
            
            if (empty($error)) {
                $documents_json = !empty($uploaded_files) ? json_encode($uploaded_files) : null;
                $result = $db->execute(
                    "INSERT INTO beneficiary_requests (member_code, beneficiary_name, beneficiary_address, beneficiary_relation, beneficiary_age, beneficiary_contact, documents, status, created_at) 
                     VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', NOW())",
                    [$member_code, $beneficiary_name, $beneficiary_address, $beneficiary_relation, $beneficiary_age, $beneficiary_contact, $documents_json],
                    'sssssss'
                );
                if ($result) {
                    $message = 'Update request submitted successfully!';
                    $pending_request = $db->getSingle("SELECT * FROM beneficiary_requests WHERE member_code = ? AND status = 'pending' ORDER BY created_at DESC LIMIT 1", [$member_code], 's');
                } else {
                    $error = 'Failed to submit request. Please try again.';
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
<title>Beneficiary — <?php echo defined('APP_NAME') ? APP_NAME : 'NHGL'; ?></title>
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
.main{max-width:720px;margin:0 auto;padding:1.25rem 1rem 6rem}

/* Alerts */
.alert{
    border-radius:var(--radius);padding:0.85rem 1rem;margin-bottom:0.9rem;font-weight:600;
    display:flex;align-items:flex-start;gap:10px;font-size:0.9rem;line-height:1.5;
}
.alert.alert-success{background:var(--success-100);border:2px solid #86efac;color:var(--success-700)}
.alert.alert-danger{background:var(--danger-100);border:2px solid #fca5a5;color:var(--danger-700)}

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

/* Current Beneficiary */
.current-box{
    background:linear-gradient(135deg,var(--brand-800) 0%,var(--brand-600) 100%);
    color:#fff;padding:1.5rem 1rem;text-align:center;border-radius:var(--radius-sm);
}
.current-avatar{
    width:64px;height:64px;border-radius:50%;background:rgba(255,255,255,0.15);
    border:2px solid rgba(255,255,255,0.3);margin:0 auto 0.75rem;
    display:flex;align-items:center;justify-content:center;
}
.current-avatar svg{width:32px;height:32px;color:var(--yellow-accent)}
.current-name{font-size:1.15rem;font-weight:800;margin-bottom:4px}
.current-rel{font-size:0.85rem;color:var(--yellow-accent);font-weight:700;text-transform:uppercase;letter-spacing:0.05em}
.current-details{margin-top:1rem;font-size:0.85rem;color:rgba(255,255,255,0.9);display:flex;flex-direction:column;gap:6px}

.empty-box{
    text-align:center;padding:2rem 1rem;border:2px dashed var(--line);border-radius:var(--radius-sm);
}
.empty-box svg{width:36px;height:36px;color:var(--ink-300);margin-bottom:8px}
.empty-box p{margin:0;font-size:0.9rem;color:var(--ink-500);font-weight:600}

/* Benefit Panel */
.benefit-block{text-align:center;padding-bottom:1rem;border-bottom:1px solid var(--line);margin-bottom:1rem}
.benefit-lbl{font-size:0.75rem;font-weight:800;color:var(--ink-500);text-transform:uppercase;margin-bottom:4px}
.benefit-val{font-size:2rem;font-weight:900;color:var(--brand-700);line-height:1.1}
.benefit-years{font-size:0.85rem;color:var(--ink-500);margin-top:4px;font-weight:600}
.benefit-row{display:flex;justify-content:space-between;font-size:0.9rem;color:var(--ink-700);padding:0.5rem 0;border-bottom:1px dashed var(--line-soft)}
.benefit-row:last-child{border-bottom:none}
.benefit-row.total{font-weight:800;color:var(--ink-900);font-size:1.05rem;border-top:2px solid var(--line);margin-top:0.5rem;padding-top:0.75rem}

/* Forms */
.form-group{margin-bottom:1rem}
.form-label{display:block;font-size:0.8rem;font-weight:800;color:var(--ink-500);text-transform:uppercase;margin-bottom:6px}
.form-control, .form-select{
    width:100%;border:1px solid var(--line);border-radius:var(--radius-sm);
    padding:0.75rem 1rem;font-size:0.95rem;font-family:inherit;background:var(--surface);
    color:var(--ink-900);outline:none;transition:all 0.15s;
}
.form-control:focus, .form-select:focus{border-color:var(--brand-500);box-shadow:0 0 0 3px rgba(61,122,184,0.15)}
.form-control:disabled, .form-select:disabled{background:var(--paper);color:var(--ink-400);cursor:not-allowed}
.btn-submit{
    background:var(--yellow-accent);color:var(--brand-900);border:none;padding:0.85rem 1rem;
    border-radius:var(--radius);font-size:0.95rem;font-weight:800;font-family:inherit;cursor:pointer;
    transition:all 0.18s;width:100%;display:inline-flex;align-items:center;justify-content:center;gap:8px;
    min-height:48px;margin-top:0.5rem;
}
.btn-submit:hover:not(:disabled){transform:translateY(-2px);box-shadow:0 4px 12px rgba(251,191,36,0.4)}
.btn-submit:disabled{opacity:0.6;cursor:not-allowed}

/* Pending Notice */
.pending-notice{
    background:var(--warning-100);border:1px solid #fcd34d;border-radius:var(--radius-sm);
    padding:1rem;margin-bottom:1rem;font-size:0.85rem;color:var(--warning-700);line-height:1.5;
}
.pending-notice strong{display:block;font-weight:800;margin-bottom:4px}

/* Rules Box */
.rules-box{background:var(--surface);border:1px solid var(--line);border-radius:var(--radius-lg);overflow:hidden;box-shadow:var(--shadow-sm);margin-bottom:1rem}
.rule-item{padding:1rem;border-bottom:1px dashed var(--line)}
.rule-item:last-child{border-bottom:none}
.rule-item h4{font-size:0.9rem;font-weight:800;color:var(--ink-900);margin:0 0 6px;display:flex;align-items:center;gap:8px}
.rule-item h4 svg{width:16px;height:16px;color:var(--brand-600)}
.rule-item p{font-size:0.85rem;color:var(--ink-700);margin:0;line-height:1.5}
.rule-item ul{margin:0;padding-left:1.2rem;font-size:0.85rem;color:var(--ink-700);line-height:1.5}
.rule-item li{margin-bottom:4px}
.rule-item li:last-child{margin-bottom:0}

/* Bottom Nav (Emoji) */
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
    .bottom-nav{position:static;max-width:720px;margin:0 auto 2rem;border-radius:var(--radius);border:1px solid var(--line);box-shadow:var(--shadow-sm)}
}
@media (max-width:540px){
    html{font-size:17px}
    .main{padding:0.85rem 0.75rem 6rem}
    .app-brand .brand-sub{display:none}
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
        <a href="dashboard.php" class="icon-btn" aria-label="Home">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
        </a>
    </div>
</header>

<main class="main">
    <?php if ($message): ?>
        <div class="alert alert-success">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="width:20px;height:20px;flex-shrink:0;"><polyline points="20 6 9 17 4 12"/></svg>
            <span><?php echo htmlspecialchars($message); ?></span>
        </div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="width:20px;height:20px;flex-shrink:0;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <span><?php echo htmlspecialchars($error); ?></span>
        </div>
    <?php endif; ?>

    <!-- Current Beneficiary -->
    <div class="card">
        <div class="card-header">
            <h2><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg> Current Beneficiary</h2>
        </div>
        <div class="card-body">
            <?php if (empty($member['beneficiary_name'])): ?>
                <div class="empty-box">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
                    <p>No beneficiary designated yet.</p>
                </div>
            <?php else: ?>
                <div class="current-box">
                    <div class="current-avatar">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    </div>
                    <div class="current-name"><?php echo htmlspecialchars($member['beneficiary_name']); ?></div>
                    <div class="current-rel"><?php echo htmlspecialchars($member['beneficiary_relation']); ?></div>
                    <div class="current-details">
                        <?php if (!empty($member['beneficiary_age'])): ?>
                            <span>Age: <?php echo htmlspecialchars($member['beneficiary_age']); ?></span>
                        <?php endif; ?>
                        <?php if (!empty($member['beneficiary_contact'])): ?>
                            <span>Contact: <?php echo htmlspecialchars($member['beneficiary_contact']); ?></span>
                        <?php endif; ?>
                        <?php if (!empty($member['beneficiary_address'])): ?>
                            <span><?php echo htmlspecialchars($member['beneficiary_address']); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Death Benefit -->
    <div class="card">
        <div class="card-header">
            <h2><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg> Estimated Death Benefit</h2>
        </div>
        <div class="card-body">
            <div class="benefit-block">
                <div class="benefit-lbl">Total Estimated Benefit</div>
                <div class="benefit-val">₱<?php echo number_format($total_benefit, 2); ?></div>
                <div class="benefit-years">Based on <?php echo $years_as_member; ?> year<?php echo $years_as_member == 1 ? '' : 's'; ?> of membership</div>
            </div>
            <div class="benefit-row">
                <span>Base Benefit (<?php echo $years_as_member; ?> yr)</span>
                <span>₱<?php echo number_format($base_benefit, 2); ?></span>
            </div>
            <div class="benefit-row">
                <span>Accidental Death Bonus</span>
                <span>₱<?php echo number_format($accident_bonus, 2); ?></span>
            </div>
            <div class="benefit-row">
                <span>Good Payer Bonus</span>
                <span>₱<?php echo number_format($good_payer_bonus, 2); ?></span>
            </div>
            <div class="benefit-row total">
                <span>Total</span>
                <span>₱<?php echo number_format($total_benefit, 2); ?></span>
            </div>
        </div>
    </div>

    <!-- Update Form -->
    <div class="card">
        <div class="card-header">
            <h2><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg> Update Beneficiary</h2>
        </div>
        <div class="card-body">
            <?php if ($pending_request): ?>
                <div class="pending-notice">
                    <strong>Pending Approval</strong>
                    Your request to change the beneficiary to <strong><?php echo htmlspecialchars($pending_request['beneficiary_name']); ?></strong> is currently being reviewed.
                </div>
            <?php endif; ?>

            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                
                <div class="form-group">
                    <label class="form-label">Full Name</label>
                    <input type="text" class="form-control" name="beneficiary_name" value="<?php echo htmlspecialchars($pending_request['beneficiary_name'] ?? $member['beneficiary_name'] ?? ''); ?>" <?php echo $pending_request ? 'disabled' : ''; ?> required>
                </div>

                <div class="form-group">
                    <label class="form-label">Relationship</label>
                    <select class="form-select" name="beneficiary_relation" <?php echo $pending_request ? 'disabled' : ''; ?> required>
                        <option value="">-- Select --</option>
                        <?php 
                        $current_rel = $pending_request['beneficiary_relation'] ?? $member['beneficiary_relation'] ?? '';
                        $relations = ['Spouse', 'Child', 'Parent', 'Sibling', 'Grandchild', 'Grandparent', 'Relative', 'Legal Dependent'];
                        foreach ($relations as $rel): ?>
                            <option value="<?php echo $rel; ?>" <?php echo $current_rel === $rel ? 'selected' : ''; ?>><?php echo $rel; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;" class="form-group">
                    <div>
                        <label class="form-label">Age</label>
                        <input type="number" class="form-control" name="beneficiary_age" value="<?php echo htmlspecialchars($pending_request['beneficiary_age'] ?? $member['beneficiary_age'] ?? ''); ?>" <?php echo $pending_request ? 'disabled' : ''; ?>>
                    </div>
                    <div>
                        <label class="form-label">Contact</label>
                        <input type="text" class="form-control" name="beneficiary_contact" value="<?php echo htmlspecialchars($pending_request['beneficiary_contact'] ?? $member['beneficiary_contact'] ?? ''); ?>" <?php echo $pending_request ? 'disabled' : ''; ?>>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Complete Address</label>
                    <textarea class="form-control" name="beneficiary_address" rows="2" <?php echo $pending_request ? 'disabled' : ''; ?> required><?php echo htmlspecialchars($pending_request['beneficiary_address'] ?? $member['beneficiary_address'] ?? ''); ?></textarea>
                </div>

                <div class="form-group">
                    <label class="form-label">Supporting Documents</label>
                    <input type="file" class="form-control" name="documents[]" multiple accept="image/*,application/pdf" <?php echo $pending_request ? 'disabled' : ''; ?>>
                    <span style="font-size:0.75rem; color:var(--ink-500); display:block; margin-top:4px;">Upload proof (Birth/Marriage Cert, Valid ID). Max 5MB per file.</span>
                </div>

                <button type="submit" name="update_beneficiary" class="btn-submit" <?php echo $pending_request ? 'disabled' : ''; ?>>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="width:16px;height:16px;"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                    <?php echo $pending_request ? 'Request Pending' : 'Submit Request'; ?>
                </button>
            </form>
        </div>
    </div>

    <!-- Rules -->
    <div class="section-title">Beneficiary Rules</div>
    <div class="rules-box">
        <div class="rule-item">
            <h4><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg> Who Can Be a Beneficiary</h4>
            <ul>
                <li>Must be a legitimate family member (spouse, child, parent, legal dependent).</li>
                <li>Must be between <strong>10 to 60 years old</strong>.</li>
                <li>Only <strong>ONE (1)</strong> beneficiary can be designated at a time.</li>
            </ul>
        </div>
        <div class="rule-item">
            <h4><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg> Required Documents</h4>
            <ul>
                <li>Birth certificate (for children)</li>
                <li>Marriage certificate (for spouse)</li>
                <li>Valid government-issued ID</li>
            </ul>
        </div>
        <div class="rule-item">
            <h4><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg> Processing Time</h4>
            <ul>
                <li>Reviewed within <strong>3–5 business days</strong>.</li>
                <li>You will be notified via email/SMS once approved.</li>
                <li>Only one pending request allowed at a time.</li>
            </ul>
        </div>
    </div>
</main>

<nav class="bottom-nav">
    <a href="dashboard.php" class="nav-item">
        <span class="icon">🏠</span><span>Home</span>
    </a>
    <a href="payments.php" class="nav-item">
        <span class="icon">💳</span><span>Payments</span>
    </a>
    <a href="profile.php" class="nav-item">
        <span class="icon">👤</span><span>Profile</span>
    </a>
    <a href="support.php" class="nav-item">
        <span class="icon">❓</span><span>Help</span>
    </a>
</nav>

</body>
</html>