<?php
// verify.php — Email verification endpoint. Public.
require_once 'includes/config.php';
require_once 'includes/db_connection.php';

$db = Database::getInstance();
$token = trim((string)($_GET['token'] ?? ''));
$state = 'invalid';
$name = '';

if (preg_match('/^[a-f0-9]{64}$/i', $token)) {
    try {
        $row = $db->getSingle(
            "SELECT user_id, full_name, email, email_verified_at FROM users WHERE email_verify_token = ? LIMIT 1",
            [$token], 's'
        );
        if ($row) {
            $name = $row['full_name'] ?? '';
            if (!empty($row['email_verified_at'])) {
                $state = 'already';
            } else {
                $db->execute(
                    "UPDATE users SET email_verified_at = NOW(), email_verify_token = NULL WHERE user_id = ?",
                    [$row['user_id']], 'i'
                );
                $state = 'verified';
            }
        }
    } catch (Throwable $e) {
        error_log('verify.php: ' . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Email Verification — <?php echo APP_NAME; ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
<style>
body{background:#f0f2f5;min-height:100vh;font-family:'Inter',sans-serif;display:flex;align-items:center;justify-content:center;margin:0;padding:24px}
.card{background:#fff;border-radius:20px;box-shadow:0 10px 30px rgba(0,0,0,.08);max-width:440px;width:100%;padding:40px 32px;text-align:center}
.icon{width:80px;height:80px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:2.5rem;margin-bottom:20px}
.icon.good{background:#dcfce7;color:#16a34a}
.icon.info{background:#dbeafe;color:#2563eb}
.icon.bad{background:#fee2e2;color:#dc2626}
h1{font-size:1.4rem;font-weight:800;color:#1e2b3c;margin-bottom:8px}
p{color:#4a5568;font-size:.95rem;line-height:1.6;margin-bottom:24px}
.btn{display:inline-block;background:linear-gradient(135deg,#1e3a5a,#2d5f92);color:#fff;padding:12px 28px;border-radius:10px;text-decoration:none;font-weight:700;font-size:.92rem;transition:transform .15s}
.btn:hover{transform:translateY(-1px);color:#fff}
.btn-secondary{background:#e2e8f0;color:#1e3a5a}
</style>
</head>
<body>
<div class="card">
<?php if ($state === 'verified'): ?>
    <div class="icon good"><i class="fas fa-check-circle"></i></div>
    <h1>Email Verified!</h1>
    <p>Thanks<?php echo $name ? ', ' . htmlspecialchars(explode(' ', $name)[0]) : ''; ?>. Your email is confirmed.</p>
    <a href="index.php" class="btn"><i class="fas fa-sign-in-alt me-2"></i>Log In &amp; Continue</a>
<?php elseif ($state === 'already'): ?>
    <div class="icon info"><i class="fas fa-info-circle"></i></div>
    <h1>Already Verified</h1>
    <p>This email has already been confirmed. You're all set.</p>
    <a href="index.php" class="btn"><i class="fas fa-sign-in-alt me-2"></i>Log In</a>
<?php else: ?>
    <div class="icon bad"><i class="fas fa-times-circle"></i></div>
    <h1>Invalid Link</h1>
    <p>This verification link is invalid or has expired. Please log in and request a new one from your account page.</p>
    <a href="index.php" class="btn btn-secondary"><i class="fas fa-home me-2"></i>Go to Login</a>
<?php endif; ?>
</div>
</body>
</html>