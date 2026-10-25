<?php
// user/welcome.php — Limited dashboard for joined/pending/rejected users.
require_once '../includes/config.php';
require_once '../includes/db_connection.php';
require_once '../includes/security.php';
require_once '../includes/auth.php';
require_once '../includes/mailer.php';

$auth->requireLogin();
$cu = $auth->getCurrentUser();
$db = Database::getInstance();

// Fetch fresh user state (in case admin changed it)
$u = $db->getSingle(
    "SELECT user_id, email, full_name, account_status, email_verified_at, email_verify_token
     FROM users WHERE user_id = ?",
    [$cu['user_id']], 'i'
);
if (!$u) { header('Location: ../logout.php'); exit; }

$status = $u['account_status'] ?? 'joined';
if ($status === 'approved') { header('Location: dashboard.php'); exit; }

// Fetch application
$app = null;
try {
    $app = $db->getSingle(
        "SELECT * FROM pending_users WHERE user_id = ? ORDER BY id DESC LIMIT 1",
        [$cu['user_id']], 'i'
    );
} catch (Throwable $e) { $app = null; }

$rejection = null;
if ($app && $app['status'] === 'rejected') {
    try {
        $rejection = $db->getSingle(
            "SELECT * FROM pending_user_rejections WHERE pending_user_id = ? ORDER BY id DESC LIMIT 1",
            [$app['id']], 'i'
        );
    } catch (Throwable $e) { $rejection = null; }
}

// Handle resend verification
$notice = ''; $error = '';
$csrf = Security::generateCSRFToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
    if (($_POST['action'] ?? '') === 'resend_verification') {
        if (!empty($u['email_verified_at'])) {
            $notice = 'Your email is already verified.';
        } else {
            $new_token = bin2hex(random_bytes(32));
            $db->execute(
                "UPDATE users SET email_verify_token = ? WHERE user_id = ?",
                [$new_token, $cu['user_id']], 'si'
            );
            $first = explode(' ', $u['full_name'] ?: 'there')[0];
            try {
                $sent = Mailer::sendVerification($u['email'], $first, $new_token);
                $notice = $sent ? 'A new verification email has been sent.' : 'Could not send email right now.';
            } catch (Throwable $e) { $notice = 'Could not send email right now.'; }
        }
    }
}

$is_verified = !empty($u['email_verified_at']);
$app_status  = $app['status'] ?? null;
$state = 'no_app';
if ($app_status === 'pending')    $state = 'pending';
if ($app_status === 'rejected')   $state = 'rejected';
$firstName = explode(' ', $u['full_name'] ?: 'Member')[0];
$welcome_new = !empty($_GET['welcome']);
$submitted_new = !empty($_GET['submitted']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Welcome — <?php echo APP_NAME; ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box}
body{background:#f4f7fc;font-family:'Inter',sans-serif;margin:0;padding:0;color:#1e2b3c;line-height:1.6;min-height:100vh}
.app-header{background:#fff;border-bottom:1px solid #e2e8f0;padding:14px 20px;display:flex;align-items:center;justify-content:space-between;box-shadow:0 2px 8px rgba(16,24,40,.05)}
.brand{display:flex;align-items:center;gap:10px;font-weight:800;font-size:1rem;color:#1e3a5a}
.brand img{width:40px;height:40px;object-fit:contain;background:#f4f7fc;border-radius:8px;padding:4px}
.logout-btn{background:#fee2e2;color:#842029;padding:8px 16px;border-radius:8px;text-decoration:none;font-weight:600;font-size:.85rem;transition:background .15s}
.logout-btn:hover{background:#fecaca;color:#842029}
.main{max-width:640px;margin:0 auto;padding:24px 16px 60px}
.hero{border-radius:16px;padding:28px 24px;margin-bottom:20px;position:relative;overflow:hidden;box-shadow:0 10px 30px rgba(16,24,40,.1)}
.hero.blue{background:linear-gradient(135deg,#1e3a5a 0%,#2d5f92 100%);color:#fff}
.hero.yellow{background:linear-gradient(135deg,#b45309 0%,#d97706 100%);color:#fff}
.hero.red{background:linear-gradient(135deg,#7f1d1d 0%,#991b1b 100%);color:#fff}
.hero.green{background:linear-gradient(135deg,#14532d 0%,#16a34a 100%);color:#fff}
.hero::before{content:'';position:absolute;top:-80px;right:-80px;width:240px;height:240px;background:radial-gradient(circle,rgba(255,255,255,.15) 0%,transparent 70%);border-radius:50%}
.hero h1{font-size:1.6rem;font-weight:900;line-height:1.2;margin:0 0 8px;position:relative}
.hero p{margin:0 0 18px;font-size:.95rem;opacity:.95;position:relative;line-height:1.5}
.hero .btn{display:inline-flex;align-items:center;gap:8px;background:#fff;color:#1e3a5a;padding:12px 24px;border-radius:10px;text-decoration:none;font-weight:800;font-size:.92rem;box-shadow:0 4px 14px rgba(0,0,0,.2);transition:transform .15s;position:relative}
.hero .btn:hover{transform:translateY(-2px);color:#1e3a5a}
.hero.red .btn{background:#fff;color:#7f1d1d}
.card{background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:20px;margin-bottom:16px;box-shadow:0 2px 8px rgba(16,24,40,.04)}
.card h3{font-size:.78rem;text-transform:uppercase;letter-spacing:.08em;font-weight:800;color:#64748b;margin:0 0 12px;display:flex;align-items:center;gap:8px}
.card h3::before{content:'';display:inline-block;width:4px;height:14px;background:#fbbf24;border-radius:2px}
.checklist{list-style:none;padding:0;margin:0}
.checklist li{display:flex;align-items:flex-start;gap:12px;padding:10px 0;border-bottom:1px solid #eef2f7;font-size:.9rem}
.checklist li:last-child{border-bottom:none}
.checklist .ci{width:24px;height:24px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.7rem;flex-shrink:0;margin-top:2px}
.checklist .ci.done{background:#dcfce7;color:#16a34a}
.checklist .ci.todo{background:#f1f5f9;color:#94a3b8}
.checklist .ci.warn{background:#fef3c7;color:#d97706}
.checklist .ci.bad{background:#fee2e2;color:#dc2626}
.checklist strong{font-weight:700;color:#1e2b3c}
.checklist small{color:#64748b;font-size:.78rem;display:block;margin-top:2px}
.notice{background:#dbeafe;color:#1e40af;padding:12px 16px;border-radius:10px;font-size:.87rem;margin-bottom:16px;border-left:4px solid #2563eb}
.error{background:#fee2e2;color:#842029;padding:12px 16px;border-radius:10px;font-size:.87rem;margin-bottom:16px;border-left:4px solid #dc2626}
.btn-inline{background:#1e3a5a;color:#fff;border:none;padding:8px 14px;border-radius:6px;font-size:.8rem;font-weight:600;cursor:pointer;font-family:inherit}
.btn-inline:hover{background:#2d5f92}
.rej-box{background:#fff8e1;border-left:4px solid #f59e0b;border-radius:10px;padding:14px 18px;margin-bottom:18px}
.rej-box .lbl{font-size:.7rem;font-weight:800;text-transform:uppercase;letter-spacing:.05em;color:#92400e;margin-bottom:4px}
.rej-box .msg{color:#78350f;font-size:.9rem;line-height:1.5}
.rej-box .note{background:#fff;padding:10px 14px;border-radius:8px;margin-top:10px;font-size:.85rem;color:#4a5568}
@media (max-width:540px){.main{padding:16px 12px 40px}.hero h1{font-size:1.4rem}}
</style>
</head>
<body>

<header class="app-header">
    <div class="brand">
        <img src="../assets/images/harana-logo.png" alt="" onerror="this.style.display='none'">
        <span>NHGL</span>
    </div>
    <a href="../logout.php" class="logout-btn" onclick="return confirm('Log out?')">
        <i class="fas fa-sign-out-alt me-1"></i>Log Out
    </a>
</header>

<main class="main">

    <?php if ($notice): ?><div class="notice"><i class="fas fa-check-circle me-1"></i><?php echo htmlspecialchars($notice); ?></div><?php endif; ?>
    <?php if ($error):  ?><div class="error"><i class="fas fa-exclamation-circle me-1"></i><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

    <?php if ($welcome_new): ?>
        <div class="notice"><i class="fas fa-party-horn me-1"></i>Welcome, <?php echo htmlspecialchars($firstName); ?>! Your account is ready.</div>
    <?php endif; ?>
    <?php if ($submitted_new): ?>
        <div class="notice"><i class="fas fa-check-circle me-1"></i>Application submitted! Admin will review it soon.</div>
    <?php endif; ?>

    <?php if (!$is_verified): ?>
    <div class="card" style="border-left:4px solid #f59e0b;background:#fffbeb">
        <h3 style="color:#92400e">Verify your email</h3>
        <p style="font-size:.9rem;color:#78350f;margin:0 0 12px">
            We sent a verification link to <strong><?php echo htmlspecialchars($u['email']); ?></strong>.
            Check your inbox (and spam folder) to confirm.
        </p>
        <form method="POST" style="margin:0">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
            <input type="hidden" name="action" value="resend_verification">
            <button type="submit" class="btn-inline"><i class="fas fa-paper-plane me-1"></i>Resend Email</button>
        </form>
    </div>
    <?php endif; ?>

    <!-- Hero based on state -->
    <?php if ($state === 'pending'): ?>
        <div class="hero blue">
            <h1>Application Under Review</h1>
            <p>Hi <?php echo htmlspecialchars($firstName); ?>, your application is being reviewed by our admin team. You'll get an email as soon as a decision is made.</p>
        </div>
    <?php elseif ($state === 'rejected'): ?>
        <div class="hero red">
            <h1>Action Needed</h1>
            <p>Hi <?php echo htmlspecialchars($firstName); ?>, your application needs a small revision before we can approve it. Please review the reason below and resubmit.</p>
            <a href="../register.php" class="btn"><i class="fas fa-edit"></i>Edit Application</a>
        </div>
    <?php else: ?>
        <div class="hero yellow">
            <h1>Complete Your Application</h1>
            <p>Hi <?php echo htmlspecialchars($firstName); ?>! Your account is set up. The next step is to fill out the full membership application so we can review and activate your account.</p>
            <a href="../register.php" class="btn"><i class="fas fa-arrow-right"></i>Start Application</a>
        </div>
    <?php endif; ?>

    <?php if ($rejection): ?>
    <div class="rej-box">
        <div class="lbl">Reason for Rejection</div>
        <div class="msg">
            <strong><?php echo htmlspecialchars($rejection['reason_label'] ?? 'Not approved'); ?></strong>
            <?php if (!empty($rejection['reason_message'])): ?>
                <br><?php echo nl2br(htmlspecialchars($rejection['reason_message'])); ?>
            <?php endif; ?>
        </div>
        <?php if (!empty($rejection['custom_reason'])): ?>
            <div class="note">
                <strong>Admin note:</strong>
                <?php echo nl2br(htmlspecialchars($rejection['custom_reason'])); ?>
            </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="card">
        <h3>Your Journey</h3>
        <ul class="checklist">
            <li>
                <span class="ci done"><i class="fas fa-check"></i></span>
                <div><strong>Account created</strong><small>Joined on <?php echo date('M j, Y'); ?></small></div>
            </li>
            <li>
                <span class="ci <?php echo $is_verified ? 'done' : 'warn'; ?>"><i class="fas fa-<?php echo $is_verified ? 'check' : 'clock'; ?>"></i></span>
                <div><strong>Email verified</strong>
                    <small><?php echo $is_verified ? 'Confirmed' : 'Check your inbox'; ?></small>
                </div>
            </li>
            <li>
                <span class="ci <?php echo $state !== 'no_app' ? 'done' : 'todo'; ?>"><i class="fas fa-<?php echo $state !== 'no_app' ? 'check' : 'circle'; ?>"></i></span>
                <div><strong>Application submitted</strong>
                    <small><?php echo $state !== 'no_app' ? 'Received' : 'Not yet submitted'; ?></small>
                </div>
            </li>
            <li>
                <span class="ci <?php echo $state === 'pending' ? 'warn' : ($state === 'rejected' ? 'bad' : 'todo'); ?>">
                    <i class="fas fa-<?php echo $state === 'pending' ? 'clock' : ($state === 'rejected' ? 'times' : 'circle'); ?>"></i>
                </span>
                <div><strong>Admin approval</strong>
                    <small>
                        <?php
                        if ($state === 'pending') echo 'Under review';
                        elseif ($state === 'rejected') echo 'Please revise and resubmit';
                        else echo 'Not started';
                        ?>
                    </small>
                </div>
            </li>
            <li>
                <span class="ci todo"><i class="fas fa-lock"></i></span>
                <div><strong>Unlock payments &amp; benefits</strong>
                    <small>Available after approval</small>
                </div>
            </li>
        </ul>
    </div>

    <div class="card" style="text-align:center;color:#64748b;font-size:.85rem">
        <i class="fas fa-question-circle me-1"></i>
        Questions? Contact the office at <a href="tel:0449406708" style="color:#2d5f92;font-weight:600">(044) 940-6708</a>
    </div>

</main>
</body>
</html>