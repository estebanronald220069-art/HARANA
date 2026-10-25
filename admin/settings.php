<?php
// admin/settings.php
require_once '../includes/config.php';
require_once '../includes/db_connection.php';
require_once '../includes/security.php';
require_once '../includes/auth.php';
require_once '../includes/settings.php';
require_once '../includes/logger.php';
require_once '../includes/qr.php';


// Never cache this page — it can render one-time backup codes
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$auth->requireLogin();
$current_user = $auth->getCurrentUser();
if (!$current_user) { $auth->logout(); exit(); }

$db = Database::getInstance();

$pending_count = 0;
if ($current_user['role'] === 'admin') {
    $pending_count = (int)($db->getSingle("SELECT COUNT(*) as cnt FROM pending_users WHERE status='pending'")['cnt'] ?? 0);
}

$success_toast = '';
$error_toast   = '';

/* ============================================================
   POST — save / reset / account actions
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error_toast = 'Invalid security token.';
    } else {
        $action = $_POST['action'] ?? '';
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;

        /* ---------- SAVE TAB ---------- */
        if ($action === 'save_tab') {
            $tab = preg_replace('/[^a-z_]/', '', $_POST['tab'] ?? '');
            $rows = Settings::all($tab);
            $saved = 0;
            $validationError = '';

            foreach ($rows as $key => $row) {
                $type = $row['value_type'];
                if ($type === 'bool') {
                    $new = isset($_POST['s_' . $key]) ? '1' : '0';
                } else {
                    if (!array_key_exists('s_' . $key, $_POST)) continue;
                    $new = trim((string)$_POST['s_' . $key]);

                    if ($type === 'int' && $new !== '' && !ctype_digit(ltrim($new, '-'))) {
                        $validationError = "“{$row['label']}” must be a whole number.";
                        break;
                    }
                    if ($type === 'float' && $new !== '' && !is_numeric($new)) {
                        $validationError = "“{$row['label']}” must be numeric.";
                        break;
                    }
                }
                $old = (string)$row['setting_value'];
                if ($old !== (string)$new) {
                    if (Settings::set($key, $new, $current_user['user_id'], $current_user['username'], $ip)) {
                        $saved++;
                    }
                }
            }

            if ($validationError) {
                $error_toast = $validationError;
            } else {
                header('Location: settings.php?tab=' . urlencode($tab) . '&success=' . ($saved > 0 ? 'saved' : 'nochange'));
                exit();
            }
        }

        /* ---------- RESET SINGLE FIELD ---------- */
        elseif ($action === 'reset_field') {
            $key = $_POST['setting_key'] ?? '';
            if ($key) {
                Settings::reset($key, $current_user['user_id'], $current_user['username'], $ip);
                $row = $db->getSingle("SELECT category FROM settings WHERE setting_key = ?", [$key], 's');
                $tab = $row['category'] ?? 'general';
                header('Location: settings.php?tab=' . urlencode($tab) . '&success=reset');
                exit();
            }
        }

        /* ---------- RESET WHOLE TAB ---------- */
        elseif ($action === 'reset_tab') {
            $tab = preg_replace('/[^a-z_]/', '', $_POST['tab'] ?? '');
            if ($tab) {
                Settings::resetCategory($tab, $current_user['user_id'], $current_user['username'], $ip);
                header('Location: settings.php?tab=' . urlencode($tab) . '&success=reset');
                exit();
            }
        }

        /* ---------- UPDATE EMAIL ---------- */
        elseif ($action === 'update_email') {
            $newEmail = trim($_POST['email'] ?? '');
            if ($newEmail === '' || !filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
                $error_toast = 'Please enter a valid email address.';
            } else {
                $exists = $db->getSingle(
                    "SELECT user_id FROM users WHERE email = ? AND user_id != ?",
                    [$newEmail, $current_user['user_id']], 'si'
                );
                if ($exists) {
                    $error_toast = 'That email is already in use by another account.';
                } else {
                    $db->execute(
                        "UPDATE users SET email = ? WHERE user_id = ?",
                        [$newEmail, $current_user['user_id']], 'si'
                    );
                    Log::audit('auth', 'EMAIL_UPDATED', 'User updated their email address', [
                        'user_id'   => $current_user['user_id'],
                        'username'  => $current_user['username'],
                        'new_email' => $newEmail,
                    ]);
                    header('Location: settings.php?tab=account&success=email_updated');
                    exit();
                }
            }
        }

        /* ---------- CHANGE PASSWORD ---------- */
        elseif ($action === 'change_password') {
            $current_pw  = $_POST['current_password'] ?? '';
            $new_pw      = $_POST['new_password'] ?? '';
            $confirm_pw  = $_POST['confirm_password'] ?? '';

            $userRow = $db->getSingle("SELECT password FROM users WHERE user_id = ?", [$current_user['user_id']], 'i');
            $minLen  = (int)Settings::get('security.min_password_length', 6);

            if (!$userRow) {
                $error_toast = 'Account not found.';
            } elseif (!password_verify($current_pw, $userRow['password'])) {
                $error_toast = 'Current password is incorrect.';
                Log::security('auth', 'PASSWORD_CHANGE_FAILED', 'Incorrect current password', [
                    'user_id'  => $current_user['user_id'],
                    'username' => $current_user['username'],
                ]);
            } elseif ($new_pw === '' || strlen($new_pw) < $minLen) {
                $error_toast = "New password must be at least $minLen characters.";
            } elseif ($new_pw !== $confirm_pw) {
                $error_toast = 'New password and confirmation do not match.';
            } else {
                $newHash = password_hash($new_pw, PASSWORD_DEFAULT);
                $db->execute("UPDATE users SET password = ? WHERE user_id = ?", [$newHash, $current_user['user_id']], 'si');
                Log::security('auth', 'PASSWORD_CHANGED', 'User changed their password', [
                    'user_id'  => $current_user['user_id'],
                    'username' => $current_user['username'],
                ]);
                header('Location: settings.php?tab=account&success=password_changed');
                exit();
            }
        }

        /* ---------- 2FA: STAGE 1 — begin enrollment ---------- */
        elseif ($action === 'setup_2fa') {
            $row = $db->getSingle(
                "SELECT two_factor_enabled FROM users WHERE user_id = ?",
                [$current_user['user_id']], 'i'
            );

            if (!empty($row['two_factor_enabled'])) {
                $error_toast = '2FA is already enabled. Disable it first to re-enroll.';
            } else {
                try {
                    $result = $auth->setup2FA((int)$current_user['user_id']);
                    $_SESSION['pending_2fa_secret']   = $result['secret'];
                    $_SESSION['pending_backup_codes'] = $result['backup_codes'];
                    header('Location: settings.php?tab=account&success=2fa_pending');
                    exit();
                } catch (Throwable $e) {
                    error_log('2FA setup failed: ' . $e->getMessage());
                    $error_toast = 'Could not start 2FA enrollment. Please try again.';
                }
            }
        }

        /* ---------- 2FA: STAGE 2 — verify and enable ---------- */
        elseif ($action === 'confirm_2fa') {
            $code = preg_replace('/\D/', '', $_POST['totp_code'] ?? '');
            if (strlen($code) !== 6) {
                $error_toast = 'Enter the 6-digit code from your authenticator app.';
            } else {
                $res = $auth->confirm2FA((int)$current_user['user_id'], $code);
                if ($res['success']) {
                    $_SESSION['show_backup_codes_once'] = $_SESSION['pending_backup_codes'] ?? [];
                    unset($_SESSION['pending_2fa_secret'], $_SESSION['pending_backup_codes']);
                    Security::generateCSRFToken(true);
                    header('Location: settings.php?tab=account&success=2fa_enabled');
                    exit();
                }
                $error_toast = $res['error'] ?? 'Verification failed.';
            }
        }

        /* ---------- 2FA: cancel enrollment ---------- */
        elseif ($action === 'cancel_2fa') {
            $auth->cancel2FA((int)$current_user['user_id']);
            unset($_SESSION['pending_2fa_secret'], $_SESSION['pending_backup_codes']);
            header('Location: settings.php?tab=account&success=2fa_cancelled');
            exit();
        }

        /* ---------- 2FA: disable (password required) ---------- */
        elseif ($action === 'disable_2fa') {
            $pw = $_POST['confirm_password'] ?? '';
            $row = $db->getSingle(
                "SELECT password FROM users WHERE user_id = ?",
                [$current_user['user_id']], 'i'
            );

            if (!$row || !password_verify($pw, $row['password'])) {
                Log::security('auth', '2FA_DISABLE_FAILED', 'Incorrect password while disabling 2FA', [
                    'user_id'  => $current_user['user_id'],
                    'username' => $current_user['username'],
                ]);
                $error_toast = 'Password is required to disable two-factor authentication.';
            } else {
                $auth->disable2FA((int)$current_user['user_id']);
                unset($_SESSION['show_backup_codes_once']);
                Security::generateCSRFToken(true);
                header('Location: settings.php?tab=account&success=2fa_disabled');
                exit();
            }
        }

        /* ---------- 2FA: dismiss backup codes ---------- */
        elseif ($action === 'ack_backup_codes') {
            unset($_SESSION['show_backup_codes_once']);
            header('Location: settings.php?tab=account&success=backup_ack');
            exit();
        }
    }
}

/* ============================================================
   FLASH
   ============================================================ */
if (isset($_GET['success'])) {
    $map = [
        'saved'            => 'Settings saved successfully.',
        'nochange'         => 'No changes to save.',
        'reset'            => 'Settings reset to their default values.',
        'email_updated'    => 'Your email address has been updated.',
        'password_changed' => 'Your password has been changed.',
        '2fa_pending'      => 'Scan the QR code, then enter the 6-digit code to finish.',
        '2fa_enabled'      => 'Two-factor authentication is now enabled. Save your backup codes.',
        '2fa_disabled'     => 'Two-factor authentication has been disabled.',
        '2fa_cancelled'    => '2FA enrollment cancelled.',
        'backup_ack'       => 'Backup codes acknowledged.',
    ];
    $success_toast = $map[$_GET['success']] ?? '';
}

/* ============================================================
   TABS + ACTIVE
   ============================================================ */
$tabs = [
    'general'    => ['General',     'cog'],
    'membership' => ['Membership',  'users'],
    'payments'   => ['Payments',    'credit-card'],
    'security'   => ['Security',    'shield-alt'],
    'account'    => ['Account',     'user-circle'],
    'logs'       => ['System Logs', 'clipboard-list'],
];
$active_tab = $_GET['tab'] ?? 'general';
if (!isset($tabs[$active_tab])) $active_tab = 'general';

/* 'logs' tab redirects to the dedicated logs page */
if ($active_tab === 'logs') {
    header('Location: system_logs.php');
    exit();
}

$settings   = ($active_tab === 'account') ? [] : Settings::all($active_tab);
$csrf_token = Security::generateCSRFToken();

/* ============================================================
   LOG COUNTS (for sidebar badge)
   ============================================================ */
$count_error    = 0;
$count_security = 0;
try {
    $count_error    = Log::count(['level' => 'error']);
    $count_security = Log::count(['level' => 'security']);
} catch (Throwable $e) {
    $count_error = 0;
    $count_security = 0;
}

/* ============================================================
   AUDIT LOG (last 30)
   ============================================================ */
$audit_log = [];
try {
    $audit_log = $db->getAll("SELECT * FROM settings_audit ORDER BY changed_at DESC LIMIT 30");
} catch (Throwable $e) { $audit_log = []; }

/* ============================================================
   ACCOUNT DATA (incl. 2FA state)
   ============================================================ */
$account = null;
$twofa_enabled  = false;
$pending_2fa    = false;
$pending_secret = null;
$backup_codes   = [];

if ($active_tab === 'account') {
    try {
        $account = $db->getSingle("SELECT * FROM users WHERE user_id = ?", [$current_user['user_id']], 'i');
    } catch (Throwable $e) { $account = null; }

    // Trust the flag ONLY — not the secret column
    $twofa_enabled  = !empty($account['two_factor_enabled']);
    $pending_secret = $_SESSION['pending_2fa_secret'] ?? null;
    $pending_2fa    = ($pending_secret !== null && !$twofa_enabled);

    if (!empty($_SESSION['show_backup_codes_once'])) {
        $backup_codes = $_SESSION['show_backup_codes_once'];
        unset($_SESSION['show_backup_codes_once']);
    }
}

/* ============================================================
   FIELD RENDERER
   ============================================================ */
function render_setting($key, $row) {
    $type      = $row['value_type'];
    $value     = (string)$row['setting_value'];
    $default   = (string)$row['default_value'];
    $isDefault = ($value === $default);
    $inputName = 's_' . $key;
    ob_start(); ?>
    <div class="setting-item">
        <div class="setting-info">
            <label class="setting-label" for="<?php echo htmlspecialchars($inputName); ?>">
                <?php echo htmlspecialchars($row['label']); ?>
            </label>
            <?php if (!empty($row['description'])): ?>
                <small class="setting-desc"><?php echo htmlspecialchars($row['description']); ?></small>
            <?php endif; ?>
        </div>

        <div class="setting-control">
            <?php if ($type === 'bool'): ?>
                <div class="form-check form-switch mb-0">
                    <input class="form-check-input" type="checkbox" role="switch"
                           id="<?php echo $inputName; ?>" name="<?php echo $inputName; ?>" value="1"
                           <?php echo $value === '1' ? 'checked' : ''; ?>>
                </div>
            <?php elseif ($type === 'int'): ?>
                <input type="number" step="1" class="form-control form-control-sm"
                       id="<?php echo $inputName; ?>" name="<?php echo $inputName; ?>"
                       value="<?php echo htmlspecialchars($value); ?>">
            <?php elseif ($type === 'float'): ?>
                <input type="number" step="0.01" class="form-control form-control-sm"
                       id="<?php echo $inputName; ?>" name="<?php echo $inputName; ?>"
                       value="<?php echo htmlspecialchars($value); ?>">
            <?php else: ?>
                <input type="text" class="form-control form-control-sm"
                       id="<?php echo $inputName; ?>" name="<?php echo $inputName; ?>"
                       value="<?php echo htmlspecialchars($value); ?>">
            <?php endif; ?>

            <?php if (!$isDefault && $type !== 'bool'): ?>
                <small class="text-muted d-block mt-1" style="font-size:.68rem;">
                    Default: <code><?php echo htmlspecialchars($default !== '' ? $default : '(empty)'); ?></code>
                </small>
            <?php endif; ?>
        </div>

        <div class="setting-actions">
            <?php if (!$isDefault): ?>
                <button type="button" class="btn btn-sm btn-outline-secondary"
                        onclick="resetField(<?php echo htmlspecialchars(json_encode($key), ENT_QUOTES); ?>)"
                        title="Reset to default (<?php echo htmlspecialchars($default !== '' ? $default : '(empty)'); ?>)">
                    <i class="fas fa-undo"></i>
                </button>
            <?php else: ?>
                <span class="btn btn-sm btn-outline-secondary disabled" title="Already at default">
                    <i class="fas fa-undo"></i>
                </span>
            <?php endif; ?>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Settings - <?php echo APP_NAME; ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
<style>
/* ============================================================ */
/* BASE                                                          */
/* ============================================================ */
* { box-sizing: border-box; }
body {
    background: #f0f2f5;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    overflow: hidden;
    margin: 0;
    padding: 0;
}
#wrapper {
    display: flex;
    width: 100%;
    height: 100vh;
    overflow: hidden;
}

/* ============================================================ */
/* SIDEBAR — dark theme matching dashboard.php                   */
/* ============================================================ */
#sidebar-wrapper {
    background: #1c1c1d;
    color: #fff;
    width: 250px;
    height: 100vh;
    overflow-y: auto;
    transition: width 0.3s ease;
    box-shadow: 4px 0 10px rgba(0,0,0,0.1);
    white-space: nowrap;
    flex-shrink: 0;
}
#sidebar-wrapper.collapsed { width: 70px; }
#sidebar-wrapper.collapsed .list-group-item span { display: none; }
#sidebar-wrapper.collapsed .list-group-item {
    text-align: center;
    padding: 15px 0;
    justify-content: center;
}
#sidebar-wrapper.collapsed .list-group-item i {
    margin-right: 0;
    width: 100%;
    text-align: center;
    font-size: 1.2rem;
}

#sidebar-wrapper .sidebar-heading {
    padding: 1rem 1rem .85rem;
    font-size: 1.1rem;
    font-weight: 700;
    border-bottom: 1px solid rgba(255,255,255,.1);
    background: #1c1c1d;
    color: white;
    display: flex;
    align-items: center;
    gap: 10px;
    line-height: 1.15;
}
#sidebar-wrapper .sidebar-heading .sidebar-logo {
    height: 34px;
    width: 34px;
    object-fit: contain;
    flex-shrink: 0;
}
#sidebar-wrapper .sidebar-heading .brand-text {
    display: flex;
    flex-direction: column;
    text-align: left;
    flex: 1;
    min-width: 0;
}
#sidebar-wrapper .sidebar-heading .brand-text .brand-acronym {
    font-size: 1.15rem;
    letter-spacing: .5px;
    font-weight: 800;
}
#sidebar-wrapper .sidebar-heading .brand-text .brand-full {
    font-size: .62rem;
    font-weight: 400;
    color: rgba(255,255,255,.7);
    letter-spacing: .2px;
    text-transform: uppercase;
    margin-top: 1px;
    white-space: normal;
    line-height: 1.15;
    max-width: 175px;
}
#sidebar-wrapper.collapsed .sidebar-heading .brand-text { display: none; }
#sidebar-wrapper.collapsed .sidebar-heading .sidebar-logo { display: none; }
#sidebar-wrapper.collapsed .sidebar-heading {
    justify-content: center;
    padding: 1rem 0;
    gap: 0;
}

#sidebar-wrapper .list-group-item {
    background: transparent;
    border: none;
    color: rgba(255,255,255,.9);
    padding: .8rem 1.2rem;
    font-weight: 500;
    transition: all .2s;
    font-size: .95rem;
    text-align: left;
    display: flex;
    align-items: center;
    gap: 10px;
}
#sidebar-wrapper .list-group-item i {
    width: 24px;
    text-align: center;
    font-size: 1rem;
    flex-shrink: 0;
}
#sidebar-wrapper .list-group-item:hover,
#sidebar-wrapper .list-group-item.active {
    background: rgba(255,255,255,.15);
    color: #fff;
    border-left: 4px solid #fff;
}
#sidebar-wrapper.collapsed .list-group-item:hover,
#sidebar-wrapper.collapsed .list-group-item.active {
    border-left: none;
    border-bottom: 2px solid #fff;
}
#sidebar-wrapper .list-group-item .badge-count {
    margin-left: auto;
    font-size: .65rem;
    padding: 3px 7px;
    line-height: 1;
    border-radius: 10px;
    flex-shrink: 0;
}
#sidebar-wrapper.collapsed .badge-count { display: none !important; }

.menu-toggle {
    background: transparent;
    border: none;
    color: white;
    font-size: 1.5rem;
    cursor: pointer;
    padding: 0 10px;
    flex-shrink: 0;
    transition: all .2s;
}
.menu-toggle:hover { transform: scale(1.1); }

/* ============================================================ */
/* MAIN CONTENT                                                  */
/* ============================================================ */
#page-content-wrapper {
    flex: 1;
    background: #f0f2f5;
    height: 100vh;
    overflow-y: auto;
    padding: 0;
    min-width: 0;
}

.navbar {
    background: #fff !important;
    box-shadow: 0 2px 4px rgba(0,0,0,0.05);
    padding: 0.7rem 1.2rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
    position: sticky;
    top: 0;
    z-index: 100;
}
.navbar-left { display: flex; align-items: center; gap: 8px; }
.navbar-brand {
    font-size: 1.2rem;
    font-weight: 500;
    color: #1c1c1d !important;
    display: flex;
    align-items: center;
}
.navbar-brand i { color: #1c1c1d; font-size: 1.2rem; }
.navbar-right { display: flex; align-items: center; gap: 20px; }

.header-logo {
    height: 30px;
    width: auto;
    margin-right: 10px;
    vertical-align: middle;
    display: none;
}
#sidebar-wrapper.collapsed ~ #page-content-wrapper .header-logo { display: inline-block; }

/* ============================================================ */
/* SETTINGS UI                                                   */
/* ============================================================ */
.settings-tabs {
    display: flex; gap: 6px; flex-wrap: wrap;
    background: white; padding: 6px 10px;
    border-radius: 10px; box-shadow: 0 2px 6px rgba(0,0,0,.06);
    margin-bottom: 16px;
}
.settings-tabs .nav-link {
    border-radius: 8px; font-size: .85rem; padding: 7px 16px;
    color: #6c757d; font-weight: 500;
    display: inline-flex; align-items: center; gap: 6px;
    transition: all .2s;
    text-decoration: none;
}
.settings-tabs .nav-link:hover { background: #f8f9fa; color: #1c1c1d; }
.settings-tabs .nav-link.active { background: #1c1c1d; color: #fff; }
.settings-tabs .nav-link .tab-badge {
    background: #ffc107;
    color: #856404;
    font-size: .6rem;
    padding: 2px 6px;
    border-radius: 10px;
    font-weight: 700;
    line-height: 1;
}

.settings-card { background:#fff; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,.05); overflow: hidden; margin-bottom: 20px; }
.settings-card-header {
    padding: 14px 20px;
    background: #1c1c1d;
    color: #fff;
}
.settings-card-header h5 { margin: 0; font-size: 1rem; font-weight: 600; }
.settings-card-body { padding: 0; }
.settings-card-body.padded { padding: 20px; }

.setting-item {
    display: flex; align-items: flex-start; gap: 16px;
    padding: 16px 20px; border-bottom: 1px solid #eef1f4;
}
.setting-item:last-child { border-bottom: none; }
.setting-info { flex: 1; min-width: 0; }
.setting-label { font-weight: 600; font-size: .88rem; color: #2c3e50; margin: 0; display: block; }
.setting-desc { display: block; color: #8896a6; font-size: .74rem; margin-top: 3px; }
.setting-control { flex: 0 0 260px; }
.setting-actions { flex: 0 0 auto; padding-top: 2px; }

.setting-item .form-control:focus,
.setting-item .form-select:focus {
    border-color: #1c1c1d;
    box-shadow: 0 0 0 3px rgba(28,28,29,0.10);
}

.setting-item .form-check-input:checked {
    background-color: #1c1c1d;
    border-color: #1c1c1d;
}
.setting-item .form-check-input:focus {
    border-color: #1c1c1d;
    box-shadow: 0 0 0 3px rgba(28,28,29,0.10);
}

.settings-footer {
    display: flex; justify-content: space-between; align-items: center;
    gap: 12px; padding: 14px 20px; background: #f8f9fc;
    border-top: 1px solid #eef1f4; flex-wrap: wrap;
}

.audit-table { font-size: .78rem; }
.audit-table td { vertical-align: middle; }
.audit-old { color: #c0392b; text-decoration: line-through; }
.audit-new { color: #28a745; font-weight: 600; }

.env-badge {
    font-size: .65rem; font-weight: 700; letter-spacing: .5px;
    padding: 3px 10px; border-radius: 20px; text-transform: uppercase;
    background: #fff3cd; color: #856404; border: 1px solid #ffeeba;
}

.account-avatar {
    width: 80px; height: 80px; border-radius: 50%;
    background: #1c1c1d;
    display: inline-flex; align-items: center; justify-content: center;
    color: white; font-size: 2rem;
}

.backup-code {
    display: inline-block;
    font-family: monospace;
    font-size: .85rem;
    background: #f8f9fc;
    padding: 6px 14px;
    border-radius: 6px;
    margin: 3px;
    border: 1px solid #e9ecef;
    letter-spacing: 1px;
}

.password-input-group { position: relative; }
.password-toggle {
    position: absolute;
    top: 50%; right: 12px;
    transform: translateY(-50%);
    background: none; border: none;
    color: #8896a6; cursor: pointer;
    padding: 4px;
}
.password-toggle:hover { color: #1c1c1d; }

/* 2FA QR wrapper */
.twofa-qr-wrap {
    background: #fff;
    border: 1px solid #e9ecef;
    border-radius: 10px;
    padding: 14px;
    display: inline-block;
}
.twofa-secret {
    font-family: monospace;
    font-size: .8rem;
    background: #f8f9fc;
    padding: 8px 12px;
    border-radius: 6px;
    letter-spacing: 1.5px;
    word-break: break-all;
    border: 1px dashed #cbd5e0;
}
.twofa-state-badge {
    font-size: .7rem;
    font-weight: 700;
    letter-spacing: .5px;
    padding: 4px 10px;
    border-radius: 20px;
    text-transform: uppercase;
}

/* Dark buttons */
.btn-primary {
    background: #1c1c1d;
    border-color: #1c1c1d;
}
.btn-primary:hover,
.btn-primary:focus,
.btn-primary:active {
    background: #2c2c2d !important;
    border-color: #2c2c2d !important;
}
.btn-outline-primary {
    color: #1c1c1d;
    border-color: #1c1c1d;
}
.btn-outline-primary:hover {
    background: #1c1c1d;
    border-color: #1c1c1d;
    color: #fff;
}

/* Responsive */
@media (max-width: 768px) {
    #sidebar-wrapper {
        position: fixed;
        left: 0; top: 0;
        transform: translateX(-100%);
        z-index: 1050;
        transition: transform .3s ease;
    }
    #sidebar-wrapper.mobile-open { transform: translateX(0); }
    #sidebar-wrapper .sidebar-heading .brand-text .brand-full { display: block; }
    #sidebar-wrapper .sidebar-heading .sidebar-logo { display: block; }
    .header-logo { display: inline-block !important; }
    .setting-item { flex-wrap: wrap; }
    .setting-control { flex-basis: 100%; order: 3; }
    .setting-actions { order: 2; }
}
</style>
</head>
<body>
<div id="wrapper">

    <!-- Sidebar -->
    <div id="sidebar-wrapper">
        <div class="sidebar-heading">
            <img src="../assets/images/harana-logo.png" alt="NHGL" class="sidebar-logo" onerror="this.style.display='none';">
            <span class="brand-text">
                <span class="brand-acronym">NHGL</span>
                <span class="brand-full">Nagkaisang Haranista sa Gintong Luzon</span>
            </span>
            <button class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></button>
        </div>
        <div class="list-group list-group-flush mt-2">
            <a href="dashboard.php" class="list-group-item"><i class="fas fa-tachometer-alt"></i><span>Dashboard</span></a>
            <a href="members.php" class="list-group-item"><i class="fas fa-users"></i><span>Members</span></a>
            <a href="manage_chapters.php" class="list-group-item"><i class="fas fa-sitemap"></i><span>Chapters &amp; Groups</span></a>
            <a href="leader_assignments.php" class="list-group-item"><i class="fas fa-user-shield"></i><span>Leader Assignment</span></a>
            <a href="council.php" class="list-group-item"><i class="fas fa-user-tie"></i><span>Council</span></a>
            <a href="payments.php" class="list-group-item"><i class="fas fa-credit-card"></i><span>Payments</span></a>
             <a href="remittances.php" class="list-group-item"><i class="fas fa-hand-holding-usd"></i><span>Remittances</span></a>
            <a href="reports.php" class="list-group-item"><i class="fas fa-chart-bar"></i><span>Reports</span></a>
            <a href="announcements.php" class="list-group-item"><i class="fas fa-bullhorn"></i><span>Send Announcement</span></a>
            <?php if ($current_user['role'] === 'admin'): ?>
            <a href="pending_users.php" class="list-group-item">
                <i class="fas fa-user-clock"></i><span>Pending</span>
                <?php if ($pending_count > 0): ?>
                    <span class="badge bg-danger badge-count"><?php echo $pending_count; ?></span>
                <?php endif; ?>
            </a>
            <?php endif; ?>

            <a href="settings.php" class="list-group-item active">
                <i class="fas fa-cog"></i><span>Settings</span>
                <?php if (($count_error + $count_security) > 0): ?>
                    <span class="badge bg-warning text-dark badge-count"><?php echo $count_error + $count_security; ?></span>
                <?php endif; ?>
            </a>
            <a href="../logout.php" class="list-group-item" onclick="return confirm('Are you sure?');"><i class="fas fa-sign-out-alt"></i><span>Logout</span></a>
        </div>
    </div>

    <!-- Page content -->
    <div id="page-content-wrapper">
        <nav class="navbar navbar-light bg-light">
            <div class="navbar-left">
                <img src="../assets/images/harana-logo.png" alt="Harana" class="header-logo" id="headerLogo" onerror="this.style.display='none';">
                <span class="navbar-brand"><i class="fas fa-cog me-2"></i>System Settings</span>
                <span class="env-badge ms-2">Production</span>
            </div>
            <div class="navbar-right">
                <span class="text-muted small"><i class="fas fa-calendar-alt me-1"></i><?php echo date('F j, Y'); ?></span>
                <span class="small"><i class="fas fa-user-circle me-2"></i><?php echo htmlspecialchars($current_user['full_name'] ?? $current_user['username']); ?></span>
            </div>
        </nav>

        <div class="container-fluid p-3">

            <!-- Toasts -->
            <div class="toast-container position-fixed top-0 end-0 p-3" style="z-index:999999;top:70px!important;">
                <div id="successToast" class="toast align-items-center text-white bg-success border-0">
                    <div class="d-flex">
                        <div class="toast-body"><i class="fas fa-check-circle me-2"></i><span id="successMessage"></span></div>
                        <button class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
                    </div>
                </div>
                <div id="errorToast" class="toast align-items-center text-white bg-danger border-0">
                    <div class="d-flex">
                        <div class="toast-body"><i class="fas fa-exclamation-circle me-2"></i><span id="errorMessage"></span></div>
                        <button class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
                    </div>
                </div>
            </div>

            <!-- Tabs -->
            <ul class="nav settings-tabs" role="tablist">
                <?php foreach ($tabs as $k => $t): ?>
                    <li class="nav-item">
                        <?php if ($k === 'logs'): ?>
                            <a class="nav-link" href="system_logs.php">
                                <i class="fas fa-<?php echo $t[1]; ?>"></i> <?php echo $t[0]; ?>
                                <?php if (($count_error + $count_security) > 0): ?>
                                    <span class="tab-badge"><?php echo $count_error + $count_security; ?></span>
                                <?php endif; ?>
                            </a>
                        <?php else: ?>
                            <a class="nav-link <?php echo $active_tab === $k ? 'active' : ''; ?>"
                               href="?tab=<?php echo $k; ?>">
                                <i class="fas fa-<?php echo $t[1]; ?>"></i> <?php echo $t[0]; ?>
                            </a>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>

            <?php if ($active_tab === 'account'): ?>
                <!-- ============================================================ -->
                <!-- ACCOUNT TAB                                                   -->
                <!-- ============================================================ -->

                <!-- Backup codes (only shown once, right after enabling 2FA) -->
                <?php if (!empty($backup_codes)): ?>
                <div class="settings-card">
                    <div class="settings-card-header" style="background: #1c1c1d;">
                        <h5><i class="fas fa-key me-2"></i>Save Your Backup Codes</h5>
                    </div>
                    <div class="settings-card-body padded">
                        <div class="alert alert-warning mb-3">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <strong>These codes will not be shown again.</strong>
                            Write them down or save them somewhere safe. Each code can be used once if you lose access to your authenticator app.
                        </div>
                        <div class="text-center mb-3">
                            <?php foreach ($backup_codes as $code): ?>
                                <span class="backup-code"><?php echo htmlspecialchars($code); ?></span>
                            <?php endforeach; ?>
                        </div>
                        <div class="text-center d-flex gap-2 justify-content-center flex-wrap">
                            <button type="button" class="btn btn-outline-primary btn-sm" onclick="window.print()">
                                <i class="fas fa-print me-1"></i>Print Codes
                            </button>
                            <form method="POST" onsubmit="return confirm('Have you saved these codes? They will not be shown again.');">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                <input type="hidden" name="action" value="ack_backup_codes">
                                <button type="submit" class="btn btn-primary btn-sm">
                                    <i class="fas fa-check me-1"></i>I've Saved Them
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Account info card -->
                <div class="settings-card">
                    <div class="settings-card-header">
                        <h5><i class="fas fa-user-circle me-2"></i>Account Information</h5>
                    </div>
                    <div class="settings-card-body padded">
                        <div class="d-flex align-items-center gap-3 flex-wrap">
                            <div class="account-avatar"><i class="fas fa-user"></i></div>
                            <div>
                                <h5 class="mb-1"><?php echo htmlspecialchars($current_user['full_name'] ?? $current_user['username']); ?></h5>
                                <div class="text-muted small">
                                    <i class="fas fa-at me-1"></i><?php echo htmlspecialchars($current_user['username']); ?>
                                    &nbsp;·&nbsp;
                                    <span class="badge bg-dark"><?php echo ucfirst(htmlspecialchars($current_user['role'] ?? 'user')); ?></span>
                                </div>
                                <div class="text-muted small mt-1">
                                    <i class="fas fa-envelope me-1"></i><?php echo htmlspecialchars($account['email'] ?? '—'); ?>
                                </div>
                            </div>
                            <div class="ms-auto text-end small text-muted">
                                <div><strong>Member since:</strong><br><?php echo !empty($account['created_at']) ? date('M d, Y', strtotime($account['created_at'])) : '—'; ?></div>
                                <?php if (!empty($account['last_login'])): ?>
                                <div class="mt-2"><strong>Last login:</strong><br><?php echo date('M d, Y H:i', strtotime($account['last_login'])); ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Email card -->
                <div class="settings-card">
                    <div class="settings-card-header">
                        <h5><i class="fas fa-envelope me-2"></i>Email Address</h5>
                    </div>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="action" value="update_email">
                        <div class="settings-card-body padded">
                            <p class="text-muted small mb-3">
                                This is where system notifications and password reset emails are sent.
                            </p>
                            <div class="row">
                                <div class="col-md-8 mb-3">
                                    <label class="form-label fw-semibold" for="email">Email Address</label>
                                    <input type="email" class="form-control" id="email" name="email"
                                           value="<?php echo htmlspecialchars($account['email'] ?? ''); ?>"
                                           placeholder="you@example.com"
                                           required maxlength="150">
                                </div>
                            </div>
                        </div>
                        <div class="settings-footer">
                            <span class="small text-muted">
                                <i class="fas fa-info-circle me-1"></i>
                                Changing your email does not change your username.
                            </span>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save me-1"></i>Save Email
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Change password card -->
                <div class="settings-card" id="change-password">
                    <div class="settings-card-header">
                        <h5><i class="fas fa-key me-2"></i>Change Password</h5>
                    </div>
                    <form method="POST" id="passwordForm" autocomplete="off">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="action" value="change_password">
                        <div class="settings-card-body padded">

                            <div class="mb-3">
                                <label class="form-label fw-semibold" for="current_password">Current Password</label>
                                <div class="password-input-group">
                                    <input type="password" class="form-control" id="current_password" name="current_password" required autocomplete="current-password">
                                    <button type="button" class="password-toggle" onclick="togglePw('current_password', this)">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-semibold" for="new_password">New Password</label>
                                    <div class="password-input-group">
                                        <input type="password" class="form-control" id="new_password" name="new_password"
                                               required minlength="<?php echo (int)Settings::get('security.min_password_length', 6); ?>"
                                               autocomplete="new-password">
                                        <button type="button" class="password-toggle" onclick="togglePw('new_password', this)">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                    <small class="text-muted">
                                        Minimum <?php echo (int)Settings::get('security.min_password_length', 6); ?> characters.
                                    </small>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-semibold" for="confirm_password">Confirm New Password</label>
                                    <div class="password-input-group">
                                        <input type="password" class="form-control" id="confirm_password" name="confirm_password"
                                               required autocomplete="new-password">
                                        <button type="button" class="password-toggle" onclick="togglePw('confirm_password', this)">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="showPwToggle" onchange="toggleAllPw(this.checked)">
                                <label class="form-check-label small" for="showPwToggle">Show passwords</label>
                            </div>

                        </div>
                        <div class="settings-footer">
                            <span class="small text-muted">
                                <i class="fas fa-info-circle me-1"></i>
                                Changing your password will not log you out of other devices.
                            </span>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save me-1"></i>Update Password
                            </button>
                        </div>
                    </form>
                </div>

                <!-- ============================================================ -->
                <!-- 2FA CARD                                                      -->
                <!-- ============================================================ -->
                <div class="settings-card" id="twofa-card">
                    <div class="settings-card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fas fa-shield-alt me-2"></i>Two-Factor Authentication</h5>
                        <?php if ($twofa_enabled): ?>
                            <span class="badge bg-success">Enabled</span>
                        <?php elseif ($pending_2fa): ?>
                            <span class="badge bg-warning text-dark">Pending</span>
                        <?php else: ?>
                            <span class="badge bg-secondary">Disabled</span>
                        <?php endif; ?>
                    </div>
                    <div class="settings-card-body padded">

                        <?php if ($twofa_enabled): ?>
                            <!-- ============ ENABLED STATE ============ -->
                            <div class="alert alert-success d-flex align-items-center gap-2 mb-3">
                                <i class="fas fa-check-circle fa-lg"></i>
                                <div>
                                    <strong>2FA is enabled.</strong>
                                    Your account is protected by an extra layer of security.
                                </div>
                            </div>
                            <p class="text-muted small mb-3">
                                If you lose access to your authenticator app, use one of your backup codes at login.
                                Disabling 2FA requires your account password.
                            </p>

                            <form method="POST" id="disable2FAForm" class="row g-2 align-items-end"
                                  onsubmit="return confirm('Disable two-factor authentication? This will make your account less secure.');">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                <input type="hidden" name="action" value="disable_2fa">
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold small" for="disable_2fa_password">
                                        Confirm your password
                                    </label>
                                    <div class="password-input-group">
                                        <input type="password" class="form-control" id="disable_2fa_password"
                                               name="confirm_password" required autocomplete="current-password">
                                        <button type="button" class="password-toggle"
                                                onclick="togglePw('disable_2fa_password', this)">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <button type="submit" class="btn btn-outline-danger w-100">
                                        <i class="fas fa-shield-alt me-1"></i>Disable 2FA
                                    </button>
                                </div>
                            </form>

                        <?php elseif ($pending_2fa): ?>
                            <!-- ============ PENDING (MID-ENROLLMENT) ============ -->
                            <div class="alert alert-info d-flex align-items-center gap-2 mb-3">
                                <i class="fas fa-qrcode fa-lg"></i>
                                <div>
                                    <strong>Almost there.</strong>
                                    Scan the QR code with your authenticator app, then enter the 6-digit code below to confirm.
                                </div>
                            </div>

                            <?php
                    $otpauthUri = Security::totpProvisioningUri(
    $pending_secret,
    $current_user['username'],
    APP_NAME
);
?>
<div class="text-center mb-3">
    <div class="twofa-qr-wrap">
        <?php echo QR::img($otpauthUri, 220, '2FA QR code'); ?>
    </div>
                                <div class="small text-muted mt-3">
                                    Can't scan? Enter this key manually:
                                </div>
                                <div class="twofa-secret mt-2 d-inline-block">
                                    <?php echo htmlspecialchars($pending_secret); ?>
                                </div>
                            </div>

                            <form method="POST" class="row g-2 align-items-end justify-content-center">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                <input type="hidden" name="action" value="confirm_2fa">
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold small" for="totp_code">6-digit code</label>
                                    <input type="text" class="form-control" id="totp_code" name="totp_code"
                                           inputmode="numeric" pattern="\d{6}" maxlength="6" required
                                           placeholder="123456" autocomplete="one-time-code"
                                           style="letter-spacing:.4em;font-family:monospace;font-size:1.1rem;text-align:center;">
                                </div>
                                <div class="col-md-6">
                                    <button type="submit" class="btn btn-success w-100">
                                        <i class="fas fa-check me-1"></i>Verify &amp; Enable
                                    </button>
                                </div>
                            </form>
                            <form method="POST" class="mt-3 text-center">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                <input type="hidden" name="action" value="cancel_2fa">
                                <button type="submit" class="btn btn-link btn-sm p-0 text-muted">
                                    Cancel enrollment
                                </button>
                            </form>

                        <?php else: ?>
                            <!-- ============ DISABLED STATE ============ -->
                            <div class="alert alert-warning d-flex align-items-center gap-2 mb-3">
                                <i class="fas fa-exclamation-triangle fa-lg"></i>
                                <div>
                                    <strong>2FA is not enabled.</strong>
                                    We strongly recommend enabling it for admin accounts.
                                </div>
                            </div>
                            <p class="text-muted small mb-3">
                                Two-factor authentication adds an extra step at login. You'll need an authenticator app
                                (Google Authenticator, Authy, Microsoft Authenticator, etc.).
                            </p>
                            <form method="POST">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                <input type="hidden" name="action" value="setup_2fa">
                                <button type="submit" class="btn btn-success">
                                    <i class="fas fa-qrcode me-1"></i>Enable 2FA
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>

            <?php else: ?>

                <!-- ============================================================ -->
                <!-- SETTINGS TABS                                                  -->
                <!-- ============================================================ -->
                <form method="POST" id="settingsForm">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="action" value="save_tab">
                    <input type="hidden" name="tab" value="<?php echo htmlspecialchars($active_tab); ?>">

                    <div class="settings-card">
                        <div class="settings-card-header">
                            <h5>
                                <i class="fas fa-<?php echo $tabs[$active_tab][1]; ?> me-2"></i>
                                <?php echo $tabs[$active_tab][0]; ?> Settings
                            </h5>
                        </div>
                        <div class="settings-card-body">
                            <?php if (empty($settings)): ?>
                                <div class="text-center py-5">
                                    <i class="fas fa-cog fa-3x text-muted mb-3"></i>
                                    <p class="text-muted mb-0">No settings in this category.</p>
                                </div>
                            <?php else: foreach ($settings as $key => $row): ?>
                                <?php echo render_setting($key, $row); ?>
                            <?php endforeach; endif; ?>
                        </div>

                        <?php if (!empty($settings)): ?>
                        <div class="settings-footer">
                            <button type="button" class="btn btn-outline-secondary btn-sm"
                                    onclick="resetTab('<?php echo htmlspecialchars($active_tab); ?>')">
                                <i class="fas fa-undo me-1"></i>Reset This Section
                            </button>
                            <div class="ms-auto">
                                <a href="?tab=<?php echo htmlspecialchars($active_tab); ?>" class="btn btn-secondary me-2">Cancel</a>
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save me-1"></i>Save Changes
                                </button>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </form>

                <!-- Audit Log -->
                <div class="settings-card">
                    <div class="settings-card-header" style="cursor:pointer;"
                         data-bs-toggle="collapse" data-bs-target="#auditBody">
                        <h5 class="d-flex justify-content-between align-items-center">
                            <span><i class="fas fa-history me-2"></i>Recent Changes</span>
                            <i class="fas fa-chevron-down" style="font-size:.8rem;"></i>
                        </h5>
                    </div>
                    <div class="collapse" id="auditBody">
                        <div class="settings-card-body p-0">
                            <?php if (empty($audit_log)): ?>
                                <div class="text-center py-4">
                                    <p class="text-muted mb-0 small">No settings have been changed yet.</p>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-sm table-hover mb-0 audit-table">
                                        <thead class="table-light">
                                            <tr>
                                                <th>When</th>
                                                <th>Setting</th>
                                                <th>Old Value</th>
                                                <th>New Value</th>
                                                <th>By</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($audit_log as $a):
                                                $old = (string)$a['old_value'];
                                                $new = (string)$a['new_value'];
                                                if ($old === '') $old = '(empty)';
                                                if ($new === '') $new = '(empty)';
                                            ?>
                                            <tr>
                                                <td><small><?php echo date('M d, Y H:i', strtotime($a['changed_at'])); ?></small></td>
                                                <td><code style="font-size:.72rem;"><?php echo htmlspecialchars($a['setting_key']); ?></code></td>
                                                <td class="audit-old text-truncate" style="max-width:180px;"><?php echo htmlspecialchars($old); ?></td>
                                                <td class="audit-new text-truncate" style="max-width:180px;"><?php echo htmlspecialchars($new); ?></td>
                                                <td><small><?php echo htmlspecialchars($a['admin_name'] ?? 'System'); ?></small></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

            <?php endif; ?>

        </div>
    </div>
</div>

<!-- Hidden reset forms -->
<form method="POST" id="resetFieldForm" style="display:none;">
    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
    <input type="hidden" name="action" value="reset_field">
    <input type="hidden" name="setting_key" id="resetFieldKey">
</form>
<form method="POST" id="resetTabForm" style="display:none;">
    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
    <input type="hidden" name="action" value="reset_tab">
    <input type="hidden" name="tab" id="resetTabName">
</form>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
/* Sidebar */
const sidebar = document.getElementById('sidebar-wrapper');
document.getElementById('menuToggle')?.addEventListener('click', () => {
    sidebar.classList.toggle('collapsed');
    localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
});
if (localStorage.getItem('sidebarCollapsed') === 'true') sidebar.classList.add('collapsed');

/* Toasts */
const successToast = new bootstrap.Toast(document.getElementById('successToast'));
const errorToast   = new bootstrap.Toast(document.getElementById('errorToast'));
<?php if ($success_toast): ?>
document.getElementById('successMessage').textContent = <?php echo json_encode($success_toast); ?>;
successToast.show();
<?php endif; ?>
<?php if ($error_toast): ?>
document.getElementById('errorMessage').textContent = <?php echo json_encode($error_toast); ?>;
errorToast.show();
<?php endif; ?>

/* Reset field */
function resetField(key) {
    if (!confirm('Reset "' + key + '" to its default value?')) return;
    document.getElementById('resetFieldKey').value = key;
    document.getElementById('resetFieldForm').submit();
}

/* Reset tab */
function resetTab(tab) {
    if (!confirm('Reset every setting in this section to its default value?\n\nThis cannot be undone.')) return;
    document.getElementById('resetTabName').value = tab;
    document.getElementById('resetTabForm').submit();
}

/* Password visibility */
function togglePw(fieldId, btn) {
    const field = document.getElementById(fieldId);
    const icon  = btn.querySelector('i');
    if (field.type === 'password') {
        field.type = 'text';
        icon.classList.remove('fa-eye');
        icon.classList.add('fa-eye-slash');
    } else {
        field.type = 'password';
        icon.classList.remove('fa-eye-slash');
        icon.classList.add('fa-eye');
    }
}
function toggleAllPw(show) {
    ['current_password','new_password','confirm_password'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.type = show ? 'text' : 'password';
    });
}

/* Client-side password match check */
const pwForm = document.getElementById('passwordForm');
if (pwForm) {
    pwForm.addEventListener('submit', function(e) {
        const np = document.getElementById('new_password').value;
        const cp = document.getElementById('confirm_password').value;
        if (np !== cp) {
            e.preventDefault();
            document.getElementById('errorMessage').textContent = 'New password and confirmation do not match.';
            errorToast.show();
        }
    });
}

/* Dirty-state warning (settings form only) */
let formDirty = false;
const sForm = document.getElementById('settingsForm');
if (sForm) {
    sForm.addEventListener('input', () => { formDirty = true; });
    sForm.addEventListener('submit', () => { formDirty = false; });
}
window.addEventListener('beforeunload', (e) => {
    if (formDirty) { e.preventDefault(); e.returnValue = ''; }
});

/* Auto-advance TOTP input */
const totpInput = document.getElementById('totp_code');
if (totpInput) {
    totpInput.addEventListener('input', function() {
        this.value = this.value.replace(/[^0-9]/g, '');
    });
}
</script>
</body>
</html>