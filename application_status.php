<?php
// application_status.php  (public — no login required)
// ============================================================
// Applicant-facing status check page.
//
// The applicant enters their email (and optionally their
// username or member code for extra verification) and sees:
//   - Pending     : "Your application is under review."
//   - Rejected    : the reason + custom note
//   - Approved    : "Your account has been approved."
//   - Not found   : generic message
//
// Security:
//   - Requires TWO pieces of info (email + username OR member code)
//     to prevent random email probing.
//   - Rate-limited per IP (5 attempts per 15 minutes).
//   - All output HTML-escaped.
// ============================================================

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db_connection.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/rejection_reasons.php';

$db = Database::getInstance();

/* ============================================================
   Simple IP-based rate limiter (session-backed fallback)
   ============================================================ */
function getClientIp(): string {
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function rateLimitOk(string $bucket, int $max = 5, int $windowSeconds = 900): bool {
    $key = 'rl_' . $bucket . '_' . md5(getClientIp());
    $now = time();

    if (!isset($_SESSION[$key])) {
        $_SESSION[$key] = ['count' => 0, 'reset' => $now + $windowSeconds];
    }

    $data = $_SESSION[$key];

    if ($now > $data['reset']) {
        $_SESSION[$key] = ['count' => 1, 'reset' => $now + $windowSeconds];
        return true;
    }

    if ($data['count'] >= $max) {
        return false;
    }

    $_SESSION[$key]['count']++;
    return true;
}

/* ============================================================
   CSRF token for the form
   ============================================================ */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$csrf_token = Security::generateCSRFToken();

/* ============================================================
   Handle submission
   ============================================================ */
$submitted = false;
$result    = null;   // 'pending' | 'rejected' | 'approved' | 'not_found' | 'rate_limited'
$data      = null;   // rejection/pending/approval detail
$formEmail = '';
$formId    = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submitted = true;

    if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $result = 'invalid_token';
    } elseif (!rateLimitOk('status_check', 5, 900)) {
        $result = 'rate_limited';
    } else {
        $formEmail = trim((string)($_POST['email'] ?? ''));
        $formId    = trim((string)($_POST['identifier'] ?? '')); // username OR member code

        if ($formEmail === '' || $formId === '') {
            $result = 'missing_fields';
        } elseif (!filter_var($formEmail, FILTER_VALIDATE_EMAIL)) {
            $result = 'invalid_email';
        } else {
            // ---- 1. Check rejection history FIRST (most relevant) ----
            try {
                $rej = $db->getSingle(
                    "SELECT * FROM pending_user_rejections
                     WHERE email = ?
                       AND (username = ? OR member_code = ?)
                     ORDER BY rejected_at DESC
                     LIMIT 1",
                    [$formEmail, $formId, $formId],
                    'sss'
                );
            } catch (Throwable $e) {
                $rej = null;
            }

            if ($rej) {
                $result = 'rejected';
                $data   = $rej;
            } else {
                // ---- 2. Check pending queue ----
                try {
                    $pending = $db->getSingle(
                        "SELECT id, first_name, last_name, username, member_code, chapter, requested_at, status
                         FROM pending_users
                         WHERE email = ?
                           AND (username = ? OR member_code = ?)
                         ORDER BY requested_at DESC
                         LIMIT 1",
                        [$formEmail, $formId, $formId],
                        'sss'
                    );
                } catch (Throwable $e) {
                    $pending = null;
                }

                if ($pending && $pending['status'] === 'pending') {
                    $result = 'pending';
                    $data   = $pending;
                } elseif ($pending && $pending['status'] === 'rejected') {
                    // Rejected status but no record in history (legacy)
                    $result = 'rejected';
                    $data   = [
                        'full_name'      => trim(($pending['first_name'] ?? '') . ' ' . ($pending['last_name'] ?? '')),
                        'reason_label'   => 'Not approved',
                        'reason_message' => 'Your application was not approved. Please contact the admin office for details.',
                        'custom_reason'  => '',
                        'rejected_at'    => null,
                    ];
                } else {
                    // ---- 3. Check if a real user account exists ----
                    try {
                       $user = $db->getSingle(
    "SELECT user_id, username, full_name, email, is_active, created_at, account_status
     FROM users
     WHERE email = ?
       AND username = ?
     LIMIT 1",
    [$formEmail, $formId],
    'ss'
);
                    } catch (Throwable $e) {
                        $user = null;
                    }

                    if ($user && (int)$user['is_active'] === 1) {
    $acct = $user['account_status'] ?? 'approved';
    if ($acct === 'approved') {
        $result = 'approved';
        $data   = $user;
    } elseif ($acct === 'pending_approval') {
        // Fall through to pending_users branch on next load
        $result = 'not_found';
    } else {
        // joined or rejected — show account_only state
        $result = 'account_only';
        $data   = $user;
    }
} else {
    $result = 'not_found';
}
                }
            }
        }
    }
}

/* ============================================================
   Helper: pretty date
   ============================================================ */
function fmtDate(?string $d): string {
    if (!$d) return '—';
    $t = strtotime($d);
    return $t ? date('F j, Y g:i A', $t) : '—';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Application Status - <?php echo defined('APP_NAME') ? APP_NAME : 'NHGL'; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
    * { box-sizing: border-box; }

    body {
        background: #f0f2f5;
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        min-height: 100vh;
        margin: 0;
        padding: 24px 12px 60px;
        display: flex;
        flex-direction: column;
        align-items: center;
    }

    /* ---------- Brand header ---------- */
    .brand-header {
        text-align: center;
        margin-bottom: 24px;
    }
    .brand-header .logo {
        height: 70px;
        width: auto;
        margin-bottom: 8px;
    }
    .brand-header h1 {
        font-size: 1.35rem;
        font-weight: 800;
        color: #1c1c1d;
        letter-spacing: .3px;
        margin: 0;
    }
    .brand-header p {
        font-size: .78rem;
        color: #6c757d;
        margin: 4px 0 0;
        letter-spacing: .2px;
        text-transform: uppercase;
    }

    /* ---------- Card ---------- */
    .status-card {
        background: #fff;
        border-radius: 14px;
        box-shadow: 0 4px 24px rgba(0,0,0,.08);
        max-width: 560px;
        width: 100%;
        overflow: hidden;
    }
    .status-card .card-header-dark {
        background: #1c1c1d;
        color: #fff;
        padding: 18px 24px;
    }
    .status-card .card-header-dark h5 {
        margin: 0;
        font-size: 1.05rem;
        font-weight: 600;
    }
    .status-card .card-header-dark small {
        color: rgba(255,255,255,.7);
        font-size: .78rem;
    }
    .status-card .card-body {
        padding: 28px 26px;
    }

    /* ---------- Form ---------- */
    .form-label {
        font-weight: 600;
        color: #2c3e50;
        font-size: .85rem;
        margin-bottom: 6px;
    }
    .form-control {
        border: 1px solid #dee2e6;
        border-radius: 8px;
        padding: 11px 14px;
        font-size: .92rem;
        transition: all .2s;
    }
    .form-control:focus {
        border-color: #1c1c1d;
        box-shadow: 0 0 0 3px rgba(28,28,29,.10);
        outline: none;
    }
    .form-text {
        font-size: .74rem;
        color: #8896a6;
    }

    .btn-check-status {
        background: #1c1c1d;
        color: #fff;
        border: none;
        border-radius: 8px;
        padding: 12px;
        font-weight: 600;
        width: 100%;
        font-size: .95rem;
        transition: all .2s;
    }
    .btn-check-status:hover {
        background: #2c2c2d;
        transform: translateY(-1px);
        box-shadow: 0 6px 16px rgba(28,28,29,.20);
    }

    /* ---------- Result panels ---------- */
    .result-panel {
        border-radius: 10px;
        padding: 20px;
        margin-bottom: 18px;
        border-left: 5px solid;
    }
    .result-panel.pending  { background: #fff8e1; border-color: #ffc107; }
    .result-panel.rejected { background: #fdecee; border-color: #dc3545; }
    .result-panel.approved { background: #d1e7dd; border-color: #28a745; }
    .result-panel.not_found{ background: #f8f9fa; border-color: #adb5bd; }
    .result-panel.error    { background: #f8d7da; border-color: #dc3545; }

    .result-panel .icon {
        font-size: 2.2rem;
        margin-bottom: 8px;
        display: block;
    }
    .result-panel.pending  .icon { color: #856404; }
    .result-panel.rejected .icon { color: #842029; }
    .result-panel.approved .icon { color: #0f5132; }
    .result-panel.not_found .icon { color: #6c757d; }
    .result-panel.error    .icon { color: #842029; }

    .result-panel h4 {
        font-size: 1.05rem;
        font-weight: 700;
        margin-bottom: 6px;
    }
    .result-panel.pending  h4 { color: #856404; }
    .result-panel.rejected h4 { color: #842029; }
    .result-panel.approved h4 { color: #0f5132; }
    .result-panel.not_found h4 { color: #495057; }
    .result-panel.error    h4 { color: #842029; }

    .result-panel p {
        font-size: .88rem;
        margin-bottom: 8px;
        color: #4a4a4a;
        line-height: 1.55;
    }

    /* Reason detail box (inside rejected panel) */
    .reason-detail {
        background: #fff;
        border: 1px dashed #dc3545;
        border-radius: 8px;
        padding: 14px 16px;
        margin-top: 12px;
    }
    .reason-detail .rlabel {
        font-size: .68rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .5px;
        color: #842029;
        margin-bottom: 4px;
    }
    .reason-detail .rmain {
        font-size: .98rem;
        font-weight: 700;
        color: #842029;
        margin-bottom: 6px;
    }
    .reason-detail .rmsg {
        font-size: .85rem;
        color: #4a4a4a;
        white-space: pre-wrap;
        line-height: 1.5;
    }
    .reason-detail .rcustom {
        margin-top: 10px;
        padding: 10px 12px;
        background: #fff8e1;
        border-left: 3px solid #ffc107;
        border-radius: 4px;
        font-size: .84rem;
        color: #4a4a4a;
    }

    /* Timeline meta */
    .meta-line {
        font-size: .76rem;
        color: #8896a6;
        margin-top: 12px;
        padding-top: 12px;
        border-top: 1px solid rgba(0,0,0,.05);
    }
    .meta-line i { margin-right: 4px; }

    /* Footer */
    .page-footer {
        text-align: center;
        color: #8896a6;
        font-size: .74rem;
        margin-top: 28px;
    }
    .page-footer a {
        color: #1c1c1d;
        text-decoration: none;
        font-weight: 600;
    }
    .page-footer a:hover { text-decoration: underline; }
    </style>
</head>
<body>

<!-- Brand header -->
<div class="brand-header">
    <img src="assets/images/harana-logo.png" alt="NHGL" class="logo" onerror="this.style.display='none';">
    <h1>Nagkaisang Haranista sa Gintong Luzon, Inc.</h1>
    <p>Membership Application Status</p>
</div>

<!-- Main card -->
<div class="status-card">

    <div class="card-header-dark">
        <h5><i class="fas fa-search me-2"></i>Check Your Application Status</h5>
        <small>Enter the email you used when applying, and either your username or member code.</small>
    </div>

    <div class="card-body">

        <?php if (!$submitted): ?>
            <!-- ============================================================ -->
            <!-- INITIAL FORM                                                  -->
            <!-- ============================================================ -->
            <form method="POST" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">

                <div class="mb-3">
                    <label class="form-label" for="email">
                        <i class="fas fa-envelope me-1"></i>Email Address
                    </label>
                    <input type="email" name="email" id="email" class="form-control"
                           placeholder="you@example.com" required maxlength="150">
                </div>

                <div class="mb-3">
                    <label class="form-label" for="identifier">
                        <i class="fas fa-id-badge me-1"></i>Username <span class="text-muted">or</span> Member Code
                    </label>
                    <input type="text" name="identifier" id="identifier" class="form-control"
                           placeholder="e.g. juandc123  or  NHGL-2026-0042" required maxlength="100">
                    <div class="form-text">
                        One of these was shown on your application confirmation.
                    </div>
                </div>

                <button type="submit" class="btn-check-status">
                    <i class="fas fa-search me-2"></i>Check Status
                </button>
            </form>

        <?php else: ?>

            <?php if ($result === 'rejected'): ?>
                <!-- ============================================================ -->
                <!-- REJECTED                                                      -->
                <!-- ============================================================ -->
                <div class="result-panel rejected">
                    <i class="fas fa-times-circle icon"></i>
                    <h4>Application Not Approved</h4>
                    <p>
                        Dear <strong><?php
                            $fn = '';
                            if (!empty($data['full_name'])) $fn = $data['full_name'];
                            elseif (!empty($data['first_name'])) $fn = trim($data['first_name'] . ' ' . ($data['last_name'] ?? ''));
                            echo htmlspecialchars($fn !== '' ? $fn : 'Applicant');
                        ?></strong>,
                    </p>
                    <p>
                        After reviewing your application, we regret to inform you that we are unable to approve
                        it at this time. Please review the reason below.
                    </p>

                    <div class="reason-detail">
                        <div class="rlabel">Reason</div>
                        <div class="rmain"><?php echo htmlspecialchars($data['reason_label'] ?? 'Not approved'); ?></div>
                        <div class="rmsg"><?php echo htmlspecialchars($data['reason_message'] ?? ''); ?></div>

                        <?php if (!empty($data['custom_reason'])): ?>
                            <div class="rcustom">
                                <strong style="color:#856404;font-size:.7rem;text-transform:uppercase;letter-spacing:.4px;">Additional note from admin</strong>
                                <div style="margin-top:4px;"><?php echo nl2br(htmlspecialchars($data['custom_reason'])); ?></div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if (!empty($data['rejected_at'])): ?>
                        <div class="meta-line">
                            <i class="fas fa-clock"></i>Reviewed on: <?php echo htmlspecialchars(fmtDate($data['rejected_at'])); ?>
                        </div>
                    <?php endif; ?>

                    <p style="margin-top:14px;font-size:.82rem;color:#6c757d;">
                        If you believe this decision was made in error, or if you would like to submit updated
                        documents, please contact the admin office.
                    </p>
                </div>

            <?php elseif ($result === 'pending'): ?>
                <!-- ============================================================ -->
                <!-- PENDING                                                       -->
                <!-- ============================================================ -->
                <div class="result-panel pending">
                    <i class="fas fa-clock icon"></i>
                    <h4>Application Under Review</h4>
                    <p>
                        Dear <strong><?php echo htmlspecialchars(trim(($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? '')) ?: 'Applicant'); ?></strong>,
                    </p>
                    <p>
                        Your application is still being processed. Our team is reviewing your submitted documents
                        and information. You will receive an email and SMS notification once a decision is made.
                    </p>

                    <div class="meta-line">
                        <i class="fas fa-calendar-alt"></i>Submitted on: <?php echo htmlspecialchars(fmtDate($data['requested_at'] ?? null)); ?>
                        <?php if (!empty($data['member_code'])): ?>
                            &nbsp;·&nbsp;
                            <i class="fas fa-id-badge"></i>Member Code: <?php echo htmlspecialchars($data['member_code']); ?>
                        <?php endif; ?>
                        <?php if (!empty($data['chapter'])): ?>
                            &nbsp;·&nbsp;
                            <i class="fas fa-sitemap"></i>Chapter: <?php echo htmlspecialchars($data['chapter']); ?>
                        <?php endif; ?>
                    </div>
                </div>

            <?php elseif ($result === 'approved'): ?>
                <!-- ============================================================ -->
                <!-- APPROVED                                                      -->
                <!-- ============================================================ -->
                <div class="result-panel approved">
                    <i class="fas fa-check-circle icon"></i>
                    <h4>Application Approved!</h4>
                    <p>
                        Congratulations, <strong><?php echo htmlspecialchars($data['full_name'] ?? $data['username'] ?? 'Member'); ?></strong>!
                    </p>
                    <p>
                        Your membership application has been approved. You can now log in to your account using
                        the username and password you registered with.
                    </p>

                    <div class="meta-line">
                        <i class="fas fa-user-circle"></i>Username: <strong><?php echo htmlspecialchars($data['username']); ?></strong>
                        <?php if (!empty($data['created_at'])): ?>
                            &nbsp;·&nbsp;
                            <i class="fas fa-calendar-alt"></i>Approved on: <?php echo htmlspecialchars(fmtDate($data['created_at'])); ?>
                        <?php endif; ?>
                    </div>

                    <div style="margin-top:16px;">
                        <a href="index.php" class="btn-check-status" style="display:inline-block;width:auto;padding:10px 24px;text-decoration:none;">
                            <i class="fas fa-sign-in-alt me-2"></i>Go to Login
                        </a>
                    </div>
                </div>

                        <?php elseif ($result === 'account_only'): ?>
                <!-- ============================================================ -->
                <!-- ACCOUNT EXISTS BUT NO APPLICATION YET                        -->
                <!-- ============================================================ -->
                <div class="result-panel pending">
                    <i class="fas fa-user-circle icon"></i>
                    <h4>You Have an Account</h4>
                    <p>
                        Your account exists but you haven't completed the full membership
                        application yet.
                    </p>
                    <p class="text-muted small">
                        Please log in and complete your application from your welcome page.
                    </p>
                    <a href="index.php" class="btn-check-status" style="display:inline-block;width:auto;padding:10px 24px;text-decoration:none;margin-top:12px;">
                        <i class="fas fa-sign-in-alt me-2"></i>Log In to Continue
                    </a>
                </div>

            <?php elseif ($result === 'not_found'): ?>
                <!-- ============================================================ -->
                <!-- NOT FOUND                                                     -->
                <!-- ============================================================ -->
                <div class="result-panel not_found">
                    <i class="fas fa-question-circle icon"></i>
                    <h4>No Application Found</h4>
                    <p>
                        We could not find an application matching the information you provided. Please double-check
                        that your <strong>email</strong>, <strong>username</strong>, or <strong>member code</strong>
                        are entered exactly as they appeared on your application.
                    </p>
                    <p class="text-muted small mb-0">
                        If you are sure your information is correct, please contact the admin office.
                    </p>
                </div>

            <?php elseif ($result === 'rate_limited'): ?>
                <!-- ============================================================ -->
                <!-- RATE LIMITED                                                  -->
                <!-- ============================================================ -->
                <div class="result-panel error">
                    <i class="fas fa-ban icon"></i>
                    <h4>Too Many Attempts</h4>
                    <p>
                        You have made too many status-check requests. Please wait about 15 minutes and try again.
                    </p>
                </div>

            <?php elseif ($result === 'invalid_token'): ?>
                <div class="result-panel error">
                    <i class="fas fa-shield-alt icon"></i>
                    <h4>Security Check Failed</h4>
                    <p>Please refresh the page and try again.</p>
                </div>

            <?php elseif ($result === 'invalid_email'): ?>
                <div class="result-panel error">
                    <i class="fas fa-exclamation-circle icon"></i>
                    <h4>Invalid Email</h4>
                    <p>Please enter a valid email address.</p>
                </div>

            <?php elseif ($result === 'missing_fields'): ?>
                <div class="result-panel error">
                    <i class="fas fa-exclamation-circle icon"></i>
                    <h4>Missing Information</h4>
                    <p>Please fill in both the email and the username/member code fields.</p>
                </div>

            <?php else: ?>
                <div class="result-panel error">
                    <i class="fas fa-exclamation-triangle icon"></i>
                    <h4>Something Went Wrong</h4>
                    <p>Please try again in a moment.</p>
                </div>
            <?php endif; ?>

            <!-- "Search Again" button -->
            <?php if (in_array($result, ['rejected','pending','approved','not_found'], true)): ?>
                <div style="margin-top:20px;">
                    <a href="application_status.php" class="btn-check-status" style="display:inline-block;width:100%;text-decoration:none;text-align:center;">
                        <i class="fas fa-redo me-2"></i>Check Another Application
                    </a>
                </div>
            <?php elseif (in_array($result, ['rate_limited','invalid_email','missing_fields','invalid_token'], true)): ?>
                <div style="margin-top:20px;">
                    <a href="application_status.php" class="btn-check-status" style="display:inline-block;width:100%;text-decoration:none;text-align:center;">
                        <i class="fas fa-arrow-left me-2"></i>Try Again
                    </a>
                </div>
            <?php endif; ?>

        <?php endif; ?>

    </div>
</div>

<!-- Footer -->
<div class="page-footer">
    &copy; <?php echo date('Y'); ?> Nagkaisang Haranista sa Gintong Luzon, Inc.
    &nbsp;·&nbsp;
    <a href="index.php">Back to Login</a>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>