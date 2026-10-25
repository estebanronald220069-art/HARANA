<?php
// join.php — Step 1 of 2: create account. Public page.
require_once 'includes/config.php';
require_once 'includes/db_connection.php';
require_once 'includes/security.php';
require_once 'includes/auth.php';
require_once 'includes/mailer.php';

if (session_status() === PHP_SESSION_NONE) session_start();

// Already logged in? Route them.
if ($auth->isLoggedIn()) {
    $db = Database::getInstance();
    $u = $db->getSingle("SELECT account_status FROM users WHERE user_id = ?", [$_SESSION['user_id']], 'i');
    header('Location: ' . (($u['account_status'] ?? '') === 'approved' ? 'user/dashboard.php' : 'user/welcome.php'));
    exit;
}

$db = Database::getInstance();
$error = '';
$success = '';
$prefill_first = '';
$prefill_last = '';
$prefill_email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please refresh.';
    } else {
        $first_name = Security::validateInput($_POST['first_name'] ?? '', 'name', ['max' => 50]);
        $last_name  = Security::validateInput($_POST['last_name']  ?? '', 'name', ['max' => 50]);
        $email      = Security::validateInput($_POST['email']      ?? '', 'email');
        $password   = (string)($_POST['password']         ?? '');
        $confirm    = (string)($_POST['confirm_password'] ?? '');

        $prefill_first = (string)($_POST['first_name'] ?? '');
        $prefill_last  = (string)($_POST['last_name']  ?? '');
        $prefill_email = (string)($_POST['email']      ?? '');

        if ($first_name === false || trim((string)$first_name) === '') {
            $error = 'First name is required.';
        } elseif ($last_name === false || trim((string)$last_name) === '') {
            $error = 'Last name is required.';
        } elseif ($email === false || $email === '') {
            $error = 'Please enter a valid email address.';
        } elseif (strlen($password) < 8) {
            $error = 'Password must be at least 8 characters.';
        } elseif ($password !== $confirm) {
            $error = 'Passwords do not match.';
        } else {
            // Unique check
            $existing = $db->getSingle("SELECT user_id FROM users WHERE email = ? LIMIT 1", [$email], 's');
            if ($existing) {
                $error = 'An account with this email already exists. <a href="index.php">Log in instead</a>.';
            } else {
                $username = 'p_' . bin2hex(random_bytes(5)); // 12 chars, unique
                $hash     = password_hash($password, PASSWORD_DEFAULT);
                $full     = trim($first_name . ' ' . $last_name);
                $token    = bin2hex(random_bytes(32));

                $insert = $db->execute(
                    "INSERT INTO users
                       (username, password, full_name, email, role, account_status,
                        email_verified_at, email_verify_token, is_active, created_at)
                     VALUES (?, ?, ?, ?, 'viewer', 'joined', NULL, ?, 1, NOW())",
                    [$username, $hash, $full, $email, $token],
                    'sssss'
                );

                if ($insert) {
                    $new_user_id = (int)$db->getConnection()->insert_id;

                    // Try sending verification email
                    try {
                        Mailer::sendVerification($email, $first_name, $token);
                    } catch (Throwable $e) {
                        error_log('Verification email failed for ' . $email . ': ' . $e->getMessage());
                    }

                    Security::logEvent('JOIN_CREATED', "New joined account: $email (user_id=$new_user_id)");

                    // Auto-login
                    $user_row = $db->getSingle("SELECT * FROM users WHERE user_id = ?", [$new_user_id], 'i');
                    $login = $auth->login($email, $password);
                    if ($login['success']) {
                        header('Location: user/welcome.php?welcome=1');
                        exit;
                    } else {
                        // Fallback: force session
                        $_SESSION['user_id']  = $user_row['user_id'];
                        $_SESSION['username'] = $user_row['username'];
                        $_SESSION['email']    = $user_row['email'];
                        $_SESSION['full_name']= $user_row['full_name'];
                        $_SESSION['role']     = $user_row['role'];
                        header('Location: user/welcome.php?welcome=1');
                        exit;
                    }
                } else {
                    $error = 'Could not create account. Please try again.';
                }
            }
        }
    }
}

$csrf = Security::generateCSRFToken();
$from_register = !empty($_GET['from']) && $_GET['from'] === 'register';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Create Account — <?php echo APP_NAME; ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box}
body{background:linear-gradient(135deg,#1e3a5a 0%,#2d5f92 100%);min-height:100vh;font-family:'Inter',sans-serif;display:flex;align-items:center;justify-content:center;margin:0;padding:24px}
.card{background:#fff;border-radius:20px;box-shadow:0 20px 60px rgba(0,0,0,.25);max-width:480px;width:100%;overflow:hidden}
.header{background:linear-gradient(135deg,#1e3a5a,#2d5f92);color:#fff;padding:32px 28px;text-align:center}
.header img{width:70px;height:70px;object-fit:contain;margin-bottom:12px}
.header h1{margin:0 0 4px;font-size:1.3rem;font-weight:800;letter-spacing:-.01em}
.header p{margin:0;opacity:.85;font-size:.85rem}
.body{padding:32px 28px 24px}
.step{display:inline-flex;align-items:center;gap:8px;background:#e8f0f8;color:#1e3a5a;padding:6px 14px;border-radius:100px;font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;margin-bottom:20px}
.step .num{background:#1e3a5a;color:#fff;width:22px;height:22px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.7rem}
.intro{color:#4a5568;font-size:.9rem;line-height:1.5;margin-bottom:24px}
.form-group{margin-bottom:16px}
.form-label{display:block;font-size:.82rem;font-weight:600;color:#1e3a5a;margin-bottom:6px}
.form-control{width:100%;padding:12px 14px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:.92rem;font-family:inherit;transition:border .15s,box-shadow .15s}
.form-control:focus{outline:none;border-color:#2d5f92;box-shadow:0 0 0 3px rgba(45,95,146,.12)}
.row2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.btn-primary{width:100%;padding:14px;border:none;border-radius:10px;background:linear-gradient(135deg,#1e3a5a,#2d5f92);color:#fff;font-weight:700;font-size:1rem;cursor:pointer;transition:transform .15s,box-shadow .15s;margin-top:8px}
.btn-primary:hover{transform:translateY(-1px);box-shadow:0 8px 20px rgba(30,58,90,.3)}
.alert{border-radius:10px;padding:12px 16px;font-size:.87rem;margin-bottom:18px;line-height:1.5}
.alert-danger{background:#fdecee;color:#842029;border-left:4px solid #dc3545}
.alert-info{background:#e8f0f8;color:#1e3a5a;border-left:4px solid #2d5f92}
.links{margin-top:20px;text-align:center;font-size:.85rem;color:#6c757d}
.links a{color:#2d5f92;font-weight:600;text-decoration:none}
.links a:hover{text-decoration:underline}
.footer{background:#f8f9fa;padding:14px 28px;text-align:center;color:#8896a6;font-size:.74rem;border-top:1px solid #eef2f7}
.next-steps{margin-top:24px;padding:16px 18px;background:#f4f8fc;border-radius:12px;font-size:.83rem;color:#4a5568;line-height:1.6}
.next-steps strong{color:#1e3a5a;display:block;margin-bottom:6px;font-size:.85rem}
.next-steps ol{margin:0;padding-left:20px}
.next-steps li{margin-bottom:4px}
</style>
</head>
<body>
<div class="card">
    <div class="header">
        <img src="assets/images/harana-logo.png" alt="Harana" onerror="this.style.display='none'">
        <h1>Join Harana</h1>
        <p>Create your account in 60 seconds</p>
    </div>
    <div class="body">
        <div class="step"><span class="num">1</span> Step 1 of 2 — Create Account</div>

        <?php if ($from_register): ?>
            <div class="alert alert-info">
                <i class="fas fa-info-circle me-1"></i>
                You need an account first. Then you'll be able to complete the full membership application.
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger"><i class="fas fa-exclamation-circle me-1"></i><?php echo $error; ?></div>
        <?php endif; ?>

        <p class="intro">Just your name, email, and a password. You'll fill out the full membership form after this.</p>

        <form method="POST" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">

            <div class="row2">
                <div class="form-group">
                    <label class="form-label">First Name *</label>
                    <input type="text" name="first_name" class="form-control" required maxlength="50"
                           value="<?php echo htmlspecialchars($prefill_first); ?>" autofocus>
                </div>
                <div class="form-group">
                    <label class="form-label">Last Name *</label>
                    <input type="text" name="last_name" class="form-control" required maxlength="50"
                           value="<?php echo htmlspecialchars($prefill_last); ?>">
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Email Address *</label>
                <input type="email" name="email" class="form-control" required maxlength="150"
                       value="<?php echo htmlspecialchars($prefill_email); ?>"
                       placeholder="you@example.com">
                <small style="color:#8896a6;font-size:.75rem;margin-top:4px;display:block;">
                    You'll use this email to log in.
                </small>
            </div>

            <div class="form-group">
                <label class="form-label">Password *</label>
                <input type="password" name="password" class="form-control" required minlength="8">
                <small style="color:#8896a6;font-size:.75rem;margin-top:4px;display:block;">
                    At least 8 characters.
                </small>
            </div>

            <div class="form-group">
                <label class="form-label">Confirm Password *</label>
                <input type="password" name="confirm_password" class="form-control" required minlength="8">
            </div>

            <button type="submit" class="btn-primary">
                <i class="fas fa-arrow-right me-2"></i>Create Account &amp; Continue
            </button>
        </form>

        <div class="next-steps">
            <strong><i class="fas fa-list-check me-1"></i>What happens next</strong>
            <ol>
                <li>We'll email you a verification link</li>
                <li>You complete the full membership application</li>
                <li>Admin reviews and approves</li>
                <li>You unlock payments &amp; benefits</li>
            </ol>
        </div>

        <div class="links">
            Already have an account? <a href="index.php">Log in</a>
        </div>
    </div>
    <div class="footer">&copy; <?php echo date('Y'); ?> Nagkaisang Haranista sa Gintong Luzon, Inc.</div>
</div>
</body>
</html>