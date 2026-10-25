<?php
// user/account_status.php
require_once '../includes/config.php';
require_once '../includes/db_connection.php';
require_once '../includes/security.php';
require_once '../includes/auth.php';
require_once '../includes/user_functions.php';

$auth->requireLogin();
$current_user = $auth->getCurrentUser();
$db = Database::getInstance();

$member = getUserMemberData($db, $current_user);
if (!$member && !empty($current_user['user_id'])) {
    $member = $db->getSingle("SELECT * FROM members WHERE user_id = ?", [$current_user['user_id']], 'i');
}
if (!$member && !empty($current_user['username'])) {
    $member = $db->getSingle("SELECT * FROM members WHERE username = ?", [$current_user['username']], 's');
}

$reason   = $_GET['reason'] ?? ($member['status'] ?? 'inactive');
$reason   = in_array($reason, ['deceased', 'inactive'], true) ? $reason : 'inactive';
$first    = $member['first_name'] ?? 'Member';
$fullname = trim(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? ''));

// If somehow they're active, bounce them back to the dashboard.
if (($member['status'] ?? 'active') === 'active') {
    header('Location: dashboard.php');
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Account Status — NHGL</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
:root{
    --brand-900:#1e3a5a;--brand-600:#2d5f92;--brand-100:#e8f0f8;--brand-50:#f4f8fc;
    --yellow-accent:#fbbf24;--ink-900:#1e2b3c;--ink-700:#334155;--ink-500:#64748b;
    --line:#e2e8f0;--paper:#f4f7fc;--surface:#fff;
    --radius:14px;--radius-lg:18px;
    --shadow-sm:0 2px 8px rgba(16,24,40,0.07);--shadow-lg:0 10px 26px rgba(16,24,40,0.10);
    --danger-700:#7f1d1d;--danger-100:#fee2e2;--warning-700:#78350f;--warning-100:#fef3c7;
}
*{box-sizing:border-box}
html{font-size:18px}
body{background:var(--paper);font-family:'Inter',-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;
    font-size:1rem;line-height:1.6;color:var(--ink-900);margin:0;padding:0;min-height:100vh;
    -webkit-font-smoothing:antialiased;display:flex;align-items:center;justify-content:center;padding:1rem}
.card{background:var(--surface);border-radius:var(--radius-lg);box-shadow:var(--shadow-lg);
    max-width:560px;width:100%;overflow:hidden}
.card-head{padding:2rem 2rem 1rem;text-align:center}
.icon-wrap{width:80px;height:80px;border-radius:50%;margin:0 auto 1.25rem;
    display:flex;align-items:center;justify-content:center;font-size:2.2rem;font-weight:900}
.icon-wrap.deceased{background:var(--warning-100);color:var(--warning-700)}
.icon-wrap.inactive{background:var(--danger-100);color:var(--danger-700)}
.card-head h1{font-size:1.6rem;font-weight:900;margin:0 0 0.6rem;letter-spacing:-0.02em}
.card-head p{font-size:1rem;color:var(--ink-500);margin:0;line-height:1.55}
.card-body{padding:0.5rem 2rem 2rem}
.info-box{background:var(--brand-50);border:1px solid var(--line);
    border-radius:var(--radius);padding:1.25rem;margin-bottom:1.25rem}
.info-box h3{font-size:0.85rem;text-transform:uppercase;letter-spacing:0.08em;
    font-weight:800;color:var(--brand-600);margin:0 0 0.75rem}
.info-box p{margin:0 0 0.6rem;font-size:0.95rem;line-height:1.6;color:var(--ink-700)}
.info-box p:last-child{margin-bottom:0}
.info-box strong{color:var(--ink-900);font-weight:700}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:10px;
    width:100%;padding:1rem 1.5rem;border-radius:var(--radius);
    font-size:1rem;font-weight:800;text-decoration:none;min-height:56px;
    transition:all 0.15s;border:none;font-family:inherit;cursor:pointer}
.btn-primary{background:var(--yellow-accent);color:var(--brand-900);
    box-shadow:0 4px 14px rgba(251,191,36,0.35)}
.btn-primary:hover{transform:translateY(-2px);box-shadow:0 8px 20px rgba(251,191,36,0.5)}
.btn-secondary{background:transparent;color:var(--brand-600);
    border:1.5px solid var(--line);margin-top:0.6rem}
.btn-secondary:hover{background:var(--brand-50);border-color:var(--brand-600)}
</style>
</head>
<body>

<div class="card">
    <div class="card-head">
        <?php if ($reason === 'deceased'): ?>
            <div class="icon-wrap deceased">🕊️</div>
            <h1>Our heartfelt condolences</h1>
            <p>We're deeply sorry for your loss. This account is now in memorial status.</p>
        <?php else: ?>
            <div class="icon-wrap inactive">⚠️</div>
            <h1>Your account is inactive</h1>
            <p>Your membership is currently on hold. Please contact the office to reactivate it.</p>
        <?php endif; ?>
    </div>

    <div class="card-body">
        <?php if ($reason === 'deceased'): ?>
            <div class="info-box">
                <h3>How to file a claim</h3>
                <p>
                    As the family of <strong><?php echo htmlspecialchars($fullname); ?></strong>,
                    you may be entitled to the death benefit.
                </p>
                <p>
                    Please visit our office with the following:
                </p>
                <p>
                    • Death certificate<br>
                    • Your valid ID (beneficiary)<br>
                    • Member's ID or member code: <strong><?php echo htmlspecialchars($member['member_code'] ?? ''); ?></strong>
                </p>
            </div>
            <div class="info-box">
                <h3>Office hours</h3>
                <p>
                    Monday to Friday, 8:00 AM – 5:00 PM<br>
                    MF 2024, Brgy. Singalat, Palayan City
                </p>
            </div>
            <a href="support.php" class="btn btn-primary">Contact the office</a>
            <a href="../logout.php" class="btn btn-secondary">Sign out</a>

        <?php else: ?>
            <div class="info-box">
                <h3>Why is my account inactive?</h3>
                <p>
                    <strong>Reason:</strong>
                    <?php echo htmlspecialchars($member['inactive_reason'] ?? 'Not specified'); ?>
                </p>
                <?php if (!empty($member['inactive_date'])): ?>
                    <p>
                        <strong>Since:</strong>
                        <?php echo date('F j, Y', strtotime($member['inactive_date'])); ?>
                    </p>
                <?php endif; ?>
            </div>
            <div class="info-box">
                <h3>How to reactivate</h3>
                <p>
                    Visit the office to settle any outstanding contributions.
                    Once your account is active again, your dashboard and benefits will be restored.
                </p>
                <p>
                    Member code: <strong><?php echo htmlspecialchars($member['member_code'] ?? ''); ?></strong>
                </p>
            </div>
            <a href="support.php" class="btn btn-primary">Contact the office</a>
            <a href="../logout.php" class="btn btn-secondary">Sign out</a>
        <?php endif; ?>
    </div>
</div>

</body>
</html>