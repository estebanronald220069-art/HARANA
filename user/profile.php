<?php
// user/profile.php — Phone-first form layout with emoji nav (2026-10)
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

// Pending photo request
$pending_photo = $db->getSingle(
    "SELECT * FROM member_photo_requests
     WHERE member_code = ? AND status = 'pending'
     ORDER BY requested_at DESC LIMIT 1",
    [$member_code], 's'
);

$current_photo       = $member['profile_photo'] ?? null;
$has_pending_photo   = $pending_photo ? true : false;
$pending_photo_path  = $pending_photo ? '../' . $pending_photo['photo_path'] : null;

// ---- Handle profile update ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token';
    } else {
        $contact_number    = Security::sanitize($_POST['contact_number'] ?? '');
        $alternate_number  = Security::sanitize($_POST['alternate_number'] ?? '');
        $email             = Security::sanitize($_POST['email'] ?? '');
        $present_address   = Security::sanitize($_POST['present_address'] ?? '');
        $permanent_address = Security::sanitize($_POST['permanent_address'] ?? '');

        $result = $db->execute(
            "UPDATE members SET
                contact_number = ?,
                alternate_number = ?,
                email = ?,
                present_address = ?,
                permanent_address = ?,
                updated_at = NOW()
             WHERE member_code = ?",
            [$contact_number, $alternate_number, $email, $present_address, $permanent_address, $member_code],
            'ssssss'
        );

        if ($result !== false) {
            $message = 'Profile updated successfully!';
            $member = getUserMemberData($db, $current_user);
            Security::logEvent('PROFILE_UPDATE', "Member $member_code updated contact information");
        } else {
            $error = 'Failed to update profile. Please try again.';
        }
    }
}

$csrf_token = Security::generateCSRFToken();

// Display helpers
$first_name = $member['first_name'] ?? 'Member';
$last_name  = $member['last_name']  ?? '';
$full_name  = trim($first_name . ' ' . $last_name);
$initials   = strtoupper(substr($first_name,0,1) . substr($last_name,0,1));

$photo_to_show = null;
if ($has_pending_photo && $pending_photo_path) {
    $photo_to_show = $pending_photo_path;
} elseif ($current_photo) {
    $photo_to_show = '../' . $current_photo;
}

$status       = $member['status'] ?? 'active';
$status_class = $status === 'active' ? 'chip-ok' : ($status === 'deceased' ? 'chip-dim' : 'chip-warn');

function pv($v) {
    return ($v === null || $v === '') ? '—' : htmlspecialchars((string)$v);
}

$is_admin = ($current_user['role'] === 'admin');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=2.0, user-scalable=yes">
<title>My Profile — <?php echo defined('APP_NAME') ? APP_NAME : 'NHGL'; ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
/* ============================================================
   TOKENS
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
    background:var(--surface);
    border-bottom:1px solid var(--line);
    padding:0.85rem 1rem;
    display:flex;align-items:center;justify-content:space-between;
    gap:12px;position:sticky;top:0;z-index:50;
    box-shadow:var(--shadow-sm);
}
.app-brand{display:flex;align-items:center;gap:10px;min-width:0}
.app-brand .logo-wrap{
    width:44px;height:44px;border-radius:10px;
    background:var(--brand-900);color:#fff;
    display:flex;align-items:center;justify-content:center;
    font-weight:900;font-size:1rem;flex-shrink:0;
    overflow:hidden;
}
.app-brand .logo-wrap img{
    width:100%;height:100%;object-fit:contain;
    background:#fff;padding:4px;
}
.app-brand .brand-text{display:flex;flex-direction:column;line-height:1.15;min-width:0}
.app-brand .brand-name{font-weight:800;font-size:1rem;color:var(--ink-900)}
.app-brand .brand-sub{font-size:0.7rem;color:var(--ink-500);font-weight:500;line-height:1.2}
.header-actions{display:flex;gap:8px;flex-shrink:0}
.icon-btn{
    width:44px;height:44px;border-radius:10px;
    background:var(--paper);border:1px solid var(--line);
    color:var(--ink-700);display:inline-flex;
    align-items:center;justify-content:center;
    cursor:pointer;transition:all 0.15s;text-decoration:none;
}
.icon-btn:hover{background:var(--brand-50);border-color:var(--brand-500);color:var(--brand-700)}
.icon-btn svg{width:20px;height:20px}

/* ============================================================
   MAIN
============================================================ */
.main{max-width:640px;margin:0 auto;padding:1rem 0.9rem 6rem}

/* Alerts */
.alert{
    border-radius:var(--radius);padding:0.85rem 1rem;
    margin-bottom:0.9rem;font-weight:600;
    display:flex;align-items:flex-start;gap:10px;font-size:0.9rem;
    line-height:1.5;
}
.alert.alert-success{background:var(--success-100);border:2px solid #86efac;color:var(--success-700)}
.alert.alert-danger{background:var(--danger-100);border:2px solid #fca5a5;color:var(--danger-700)}
.alert svg{width:20px;height:20px;flex-shrink:0;margin-top:1px}

/* ============================================================
   ID CARD
============================================================ */
.id-card{
    background:var(--surface);border:1px solid var(--line);
    border-radius:var(--radius-lg);overflow:hidden;
    box-shadow:var(--shadow-sm);margin-bottom:0.9rem;
}
.id-card-top{
    background:linear-gradient(135deg,var(--brand-800) 0%,var(--brand-600) 100%);
    color:#fff;padding:1.25rem 1.1rem;
    display:flex;align-items:center;gap:1rem;flex-wrap:wrap;
    position:relative;overflow:hidden;
}
.id-card-top::before{
    content:'';position:absolute;top:-60px;right:-60px;
    width:180px;height:180px;
    background:radial-gradient(circle,rgba(251,191,36,0.18) 0%,transparent 70%);
    border-radius:50%;pointer-events:none;
}
.id-photo{
    width:80px;height:80px;border-radius:var(--radius);
    overflow:hidden;background:rgba(255,255,255,0.15);
    border:2.5px solid rgba(255,255,255,0.35);
    display:flex;align-items:center;justify-content:center;
    flex-shrink:0;position:relative;z-index:2;
}
.id-photo img{width:100%;height:100%;object-fit:cover}
.id-photo .initials{
    font-size:1.75rem;font-weight:900;letter-spacing:0.02em;
    color:rgba(255,255,255,0.95);
}
.id-info{flex:1;min-width:0;position:relative;z-index:2}
.id-info .member-code{
    font-family:'Courier New',monospace;font-size:0.72rem;
    color:rgba(255,255,255,0.9);font-weight:700;
    background:rgba(255,255,255,0.15);
    padding:2px 8px;border-radius:4px;
    display:inline-block;margin-bottom:6px;
    letter-spacing:0.03em;
}
.id-info h2{
    font-size:1.2rem;font-weight:900;line-height:1.15;
    margin:0 0 6px;letter-spacing:-0.01em;
    word-break:break-word;
}
.id-info .chips{display:flex;gap:6px;flex-wrap:wrap}
.chip{
    display:inline-flex;align-items:center;gap:4px;
    padding:3px 10px;border-radius:100px;
    font-size:0.7rem;font-weight:700;line-height:1.3;
}
.chip-ok{background:rgba(220,252,231,0.9);color:var(--success-700)}
.chip-warn{background:rgba(254,243,199,0.9);color:var(--warning-700)}
.chip-dim{background:rgba(226,232,240,0.9);color:var(--ink-500)}

/* Photo action row */
.photo-actions{
    padding:0.85rem 1.1rem;
    display:flex;align-items:center;gap:10px;flex-wrap:wrap;
    border-bottom:1px solid var(--line-soft);
}
.photo-btn{
    display:inline-flex;align-items:center;gap:8px;
    padding:9px 14px;border-radius:var(--radius-sm);
    background:var(--brand-100);color:var(--brand-700);
    font-weight:700;font-size:0.85rem;border:none;
    cursor:pointer;font-family:inherit;transition:background 0.15s;
    min-height:44px;
}
.photo-btn:hover{background:var(--brand-50)}
.photo-btn svg{width:16px;height:16px}
.photo-status{
    display:inline-flex;align-items:center;gap:6px;
    padding:5px 12px;border-radius:100px;
    font-size:0.75rem;font-weight:700;
}
.photo-status.pending{background:var(--warning-100);color:var(--warning-700)}
.photo-status.approved{background:var(--success-100);color:var(--success-700)}

/* Org block */
.org-block{
    background:var(--brand-50);padding:0.9rem 1.1rem;
    border-top:1px solid var(--line-soft);
}
.org-block h3{
    font-size:0.65rem;text-transform:uppercase;letter-spacing:0.08em;
    font-weight:800;color:var(--brand-600);margin:0 0 5px;
}
.org-block .org-name{
    font-size:0.85rem;font-weight:800;color:var(--ink-900);
    line-height:1.35;margin:0 0 3px;
}
.org-block .org-line{
    font-size:0.75rem;color:var(--ink-500);
    margin:2px 0;line-height:1.45;
}

/* ============================================================
   SECTION
============================================================ */
.section{
    background:var(--surface);
    border:1px solid var(--line);
    border-radius:var(--radius-lg);
    overflow:hidden;
    box-shadow:var(--shadow-sm);
    margin-bottom:0.9rem;
}

.section-head{
    background:var(--brand-900);
    color:#fff;
    padding:0.85rem 1.1rem;
    display:flex;align-items:center;gap:10px;
    border-bottom:3px solid var(--yellow-accent);
}
.section-head .num-badge{
    width:26px;height:26px;border-radius:6px;
    background:var(--yellow-accent);color:var(--brand-900);
    display:inline-flex;align-items:center;justify-content:center;
    font-size:0.72rem;font-weight:900;flex-shrink:0;
    letter-spacing:0.02em;
}
.section-head h2{
    font-size:0.88rem;font-weight:800;margin:0;
    letter-spacing:0.04em;text-transform:uppercase;
    color:#fff;
}

.section-body{padding:1rem 1.1rem 1.15rem}
.fields{display:flex;flex-direction:column;gap:1rem}

.field-group{
    display:flex;flex-direction:column;gap:0.75rem;
    padding-bottom:0.85rem;
    border-bottom:1px dashed var(--line);
}
.field-group:last-child{border-bottom:none;padding-bottom:0}
.field-group-label{
    font-size:0.7rem;text-transform:uppercase;letter-spacing:0.08em;
    font-weight:800;color:var(--brand-600);
    padding-bottom:0.3rem;
}

.field{display:flex;flex-direction:column;gap:4px;min-width:0}
.field .lbl{
    font-size:0.72rem;text-transform:uppercase;letter-spacing:0.05em;
    font-weight:800;color:var(--ink-500);
    line-height:1.3;
}
.field .val{
    font-size:0.95rem;font-weight:600;color:var(--ink-900);
    background:var(--paper);padding:0.65rem 0.85rem;
    border-radius:var(--radius-sm);
    border:1px solid transparent;
    word-break:break-word;line-height:1.45;
    min-height:46px;display:flex;align-items:center;
}
.field .val.empty{
    color:var(--ink-400);font-style:italic;font-weight:500;
}

.field input.val,
.field input[type="email"].val,
.field input[type="text"].val{
    background:var(--surface);border:1px solid var(--line);
    font-family:inherit;font-size:1rem;width:100%;outline:none;
    transition:all 0.15s;color:var(--ink-900);
}
.field input.val:focus{
    border-color:var(--brand-500);
    box-shadow:0 0 0 3px rgba(61,122,184,0.15);
    background:#fff;
}
.field input.val::placeholder{
    color:var(--ink-400);font-weight:400;
}

.field-row{
    display:grid;
    grid-template-columns:repeat(auto-fit,minmax(0,1fr));
    gap:0.6rem;
}

.field .val .chip{
    font-size:0.72rem;padding:3px 10px;
}

.empty-block{
    padding:1.1rem;
    background:var(--paper);border-radius:var(--radius-sm);
    text-align:center;color:var(--ink-400);
    font-style:italic;font-size:0.88rem;
}

/* ============================================================
   ACTION BUTTONS
============================================================ */
.actions{
    display:flex;flex-direction:column;gap:10px;
    margin:1rem 0 0;
}
.btn-primary{
    background:var(--yellow-accent);color:var(--brand-900);
    border:none;padding:1rem 1.25rem;
    border-radius:var(--radius);font-size:1.02rem;font-weight:800;
    font-family:inherit;cursor:pointer;transition:all 0.18s;
    display:inline-flex;align-items:center;justify-content:center;gap:10px;
    box-shadow:0 4px 14px rgba(251,191,36,0.35);
    min-height:56px;letter-spacing:0.01em;
}
.btn-primary:hover{transform:translateY(-2px);box-shadow:0 8px 20px rgba(251,191,36,0.5)}
.btn-primary:active{transform:translateY(0)}
.btn-secondary{
    background:transparent;color:var(--brand-600);
    border:1.5px solid var(--line);padding:1rem 1.25rem;
    border-radius:var(--radius);font-size:0.98rem;font-weight:700;
    font-family:inherit;cursor:pointer;transition:all 0.15s;
    display:inline-flex;align-items:center;justify-content:center;gap:10px;
    min-height:56px;text-decoration:none;
}
.btn-secondary:hover{background:var(--brand-50);border-color:var(--brand-600)}
.btn-primary svg,.btn-secondary svg{width:18px;height:18px}

/* Upload progress */
.upload-progress{
    display:none;margin-top:8px;width:100%;max-width:160px;
}
.upload-progress.show{display:block}
.upload-progress .bar-track{
    height:6px;background:var(--line-soft);border-radius:100px;overflow:hidden;
}
.upload-progress .bar-fill{
    height:100%;background:var(--brand-600);border-radius:100px;
    width:0%;transition:width 0.2s;
}

/* ============================================================
   SIDEBAR DRAWER (Menu)
============================================================ */
.drawer-overlay {
    position: fixed; inset: 0; background: rgba(15,41,66,0.5);
    z-index: 1040; opacity: 0; visibility: hidden;
    transition: all 0.3s ease; backdrop-filter: blur(2px);
}
.drawer-overlay.open { opacity: 1; visibility: visible; }

.drawer {
    position: fixed; top: 0; right: -300px; width: 280px; height: 100vh;
    background: var(--surface); z-index: 1050;
    box-shadow: -5px 0 25px rgba(0,0,0,0.15);
    transition: right 0.3s ease; display: flex; flex-direction: column;
}
.drawer.open { right: 0; }

.drawer-header {
    padding: 1.25rem 1rem; border-bottom: 1px solid var(--line);
    display: flex; align-items: center; justify-content: space-between;
    background: var(--brand-50);
}
.drawer-header h3 { margin: 0; font-size: 1rem; font-weight: 800; color: var(--ink-900); }
.drawer-close {
    background: transparent; border: none; font-size: 1.25rem; color: var(--ink-500);
    cursor: pointer; padding: 4px;
}

.drawer-body { padding: 1rem; flex: 1; overflow-y: auto; display: flex; flex-direction: column; gap: 4px; }
.drawer-item {
    display: flex; align-items: center; gap: 12px; padding: 12px 16px;
    text-decoration: none; color: var(--ink-700); font-weight: 600; font-size: 0.95rem;
    border-radius: var(--radius-sm); transition: all 0.15s;
}
.drawer-item:hover { background: var(--brand-50); color: var(--brand-700); }
.drawer-item svg { width: 20px; height: 20px; color: var(--brand-600); }
.drawer-footer {
    padding: 1rem; border-top: 1px solid var(--line);
}
.btn-logout {
    display: flex; align-items: center; justify-content: center; gap: 8px;
    width: 100%; padding: 12px; background: var(--danger-100); color: var(--danger-700);
    border: none; border-radius: var(--radius-sm); font-weight: 800; font-size: 0.95rem;
    cursor: pointer; font-family: inherit; text-decoration: none;
}

/* ============================================================
   BOTTOM NAV (5 TABS)
============================================================ */
.bottom-nav{
    position:fixed;bottom:0;left:0;right:0;background:var(--surface);
    border-top:1px solid var(--line);display:flex;z-index:60;
    box-shadow:0 -4px 14px rgba(16,24,40,0.06);
}
.bottom-nav .nav-item{
    flex:1;display:flex;flex-direction:column;align-items:center;
    justify-content:center;gap:3px;padding:10px 4px 12px;text-decoration:none;
    color:var(--ink-500);font-size:0.68rem;font-weight:600;transition:all 0.2s;
    min-height:64px;position:relative;
}
.bottom-nav .nav-item:hover{color:var(--brand-600);background:var(--brand-50)}
.bottom-nav .nav-item.active{color:var(--brand-700);background:var(--brand-50)}
.bottom-nav .nav-item.active::before{
    content:'';position:absolute;top:0;left:12%;right:12%;height:3px;
    background:var(--yellow-accent);border-radius:0 0 3px 3px;
    animation:fadeIn 0.3s ease-in-out;
}
.bottom-nav .nav-item .icon{font-size:1.35rem;line-height:1;font-weight:900}
.bottom-nav .nav-item span:last-child{margin-top:2px}

@keyframes fadeIn {
    from { opacity: 0; transform: scaleX(0.5); }
    to { opacity: 1; transform: scaleX(1); }
}

/* ============================================================
   RESPONSIVE
============================================================ */
@media (min-width:700px){
    .main{padding-bottom:2rem}
    .bottom-nav{
        position:static;max-width:640px;margin:0 auto 2rem;
        border-radius:var(--radius);border:1px solid var(--line);
        box-shadow:var(--shadow-sm);
    }
    .field-row.two{grid-template-columns:1fr 1fr}
    .field-row.three{grid-template-columns:1fr 1fr 1fr}
    .bottom-nav .nav-item{font-size:0.75rem}
}

@media (max-width:540px){
    html{font-size:17px}
    .main{padding:0.85rem 0.75rem 6rem}
    .id-card-top{padding:1rem 0.9rem;gap:0.85rem}
    .id-photo{width:68px;height:68px}
    .id-info h2{font-size:1.1rem}
    .section-head{padding:0.75rem 0.9rem}
    .section-body{padding:0.9rem 0.9rem 1rem}
    .app-brand .brand-sub{display:none}
    .bottom-nav .nav-item{font-size:0.65rem}
    .bottom-nav .nav-item .icon{font-size:1.25rem}
}

@media print{
    .app-header,.bottom-nav,.actions,.photo-btn,.header-actions,.upload-progress{display:none!important}
    body{background:#fff}
    .main{padding:0;max-width:100%}
    .id-card-top{background:#1e3a5a!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}
    .section-head{background:#1e3a5a!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}
    .section,.id-card{box-shadow:none;break-inside:avoid;margin-bottom:8px}
    html{font-size:11px}
    .field .val{background:#fff;border:1px solid #ccc;padding:3px 6px;min-height:0}
    .field input.val{background:#fff;border:none}
}
</style>
</head>
<body>

<!-- Drawer Overlay -->
<div class="drawer-overlay" id="drawerOverlay"></div>

<!-- Drawer Sidebar -->
<div class="drawer" id="sideDrawer">
    <div class="drawer-header">
        <h3>Menu</h3>
        <button class="drawer-close" id="closeDrawer">&times;</button>
    </div>
    <div class="drawer-body">
        <a href="beneficiary.php" class="drawer-item">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
            Beneficiary
        </a>
        <a href="organization.php" class="drawer-item">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21h18M3 7v14M21 7v14M6 7h12M6 3h12v4H6zM9 11h1M14 11h1M9 15h1M14 15h1"/></svg>
            Organization
        </a>
        <?php if ($is_admin): ?>
        <a href="../admin/dashboard.php" class="drawer-item">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 4l3 12h14l3-12-6 7-4-7-4 7-6-7z"/></svg>
            Admin Panel
        </a>
        <?php endif; ?>
    </div>
    <div class="drawer-footer">
        <a href="../logout.php" class="btn-logout" onclick="return confirm('Sign out of your account?');">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="width:18px;height:18px;"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            Sign Out
        </a>
    </div>
</div>

<!-- App Header -->
<header class="app-header">
    <div class="app-brand">
        <div class="logo-wrap">
            <img src="../assets/images/harana-logo.png" alt="NHGL"
                 onerror="this.style.display='none';this.parentNode.textContent='NH';">
        </div>
        <div class="brand-text">
            <span class="brand-name">NHGL</span>
            <span class="brand-sub">Nagkaisang Haranista</span>
        </div>
    </div>
    <div class="header-actions">
        <!-- 3 Dots Menu Button -->
        <button class="icon-btn" id="openDrawer" aria-label="Menu">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="1"/><circle cx="12" cy="5" r="1"/><circle cx="12" cy="19" r="1"/></svg>
        </button>
    </div>
</header>

<main class="main">

    <?php if ($message): ?>
        <div class="alert alert-success">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
            <span><?php echo htmlspecialchars($message); ?></span>
        </div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <span><?php echo htmlspecialchars($error); ?></span>
        </div>
    <?php endif; ?>

    <!-- ID CARD -->
    <div class="id-card">
        <div class="id-card-top">
            <div class="id-photo" id="profilePhotoContainer">
                <?php if ($photo_to_show): ?>
                    <img src="<?php echo htmlspecialchars($photo_to_show); ?>" alt="Profile photo" id="profilePhoto">
                <?php else: ?>
                    <span class="initials"><?php echo htmlspecialchars($initials); ?></span>
                <?php endif; ?>
            </div>
            <div class="id-info">
                <span class="member-code"><?php echo htmlspecialchars($member['member_code']); ?></span>
                <h2><?php echo htmlspecialchars($full_name); ?></h2>
                <div class="chips">
                    <span class="chip <?php echo $status_class; ?>"><?php echo ucfirst($status); ?></span>
                    <?php if (!empty($member['chapter'])): ?>
                        <span class="chip chip-dim"><?php echo htmlspecialchars($member['chapter']); ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="photo-actions">
            <button type="button" class="photo-btn" id="uploadPhotoBtn">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
                Change photo
            </button>
            <?php if ($has_pending_photo): ?>
                <span class="photo-status pending">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px;"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    Pending approval
                </span>
            <?php endif; ?>
            <input type="file" id="photoInput" accept="image/*" style="display:none">
            <div class="upload-progress" id="uploadProgress">
                <div class="bar-track"><div class="bar-fill"></div></div>
            </div>
        </div>

        <div class="org-block">
            <h3>Organization</h3>
            <p class="org-name">NAGKAISANG HARANISTA SA GINTONG LUZON, PHILS. INC.</p>
            <p class="org-line">Formerly Nagkaisang Hiranista Sa Gintong Luzon, Inc. · SEC Reg. No. CN 700172104</p>
            <p class="org-line">MF 2024, Brgy. Singalat, Palayan City, Nueva Ecija · Tel. (044) 940-6708</p>
        </div>
    </div>

    <!-- FORM -->
    <form method="POST" id="profileForm">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
        <input type="hidden" name="update_profile" value="1">

        <!-- I -->
        <div class="section">
            <div class="section-head">
                <span class="num-badge">I</span>
                <h2>Personal Information</h2>
            </div>
            <div class="section-body">
                <div class="fields">
                    <div class="field-row two">
                        <div class="field">
                            <span class="lbl">Member Code</span>
                            <div class="val"><?php echo pv($member['member_code'] ?? ''); ?></div>
                        </div>
                        <div class="field">
                            <span class="lbl">Date Joined</span>
                            <div class="val"><?php echo !empty($member['date_joined']) ? date('F j, Y', strtotime($member['date_joined'])) : '—'; ?></div>
                        </div>
                    </div>
                    <div class="field">
                        <span class="lbl">Monthly Contribution</span>
                        <div class="val">₱<?php echo number_format((float)($member['monthly_contribution'] ?? 100), 2); ?></div>
                    </div>

                    <div class="field-group">
                        <div class="field-group-label">Full Name</div>
                        <div class="field">
                            <span class="lbl">Last Name</span>
                            <div class="val"><?php echo pv($member['last_name'] ?? ''); ?></div>
                        </div>
                        <div class="field">
                            <span class="lbl">First Name</span>
                            <div class="val"><?php echo pv($member['first_name'] ?? ''); ?></div>
                        </div>
                        <div class="field">
                            <span class="lbl">Middle Name</span>
                            <div class="val <?php echo empty($member['middle_name']) ? 'empty' : ''; ?>"><?php echo pv($member['middle_name'] ?? ''); ?></div>
                        </div>
                    </div>

                    <div class="field">
                        <span class="lbl">Date of Birth</span>
                        <div class="val"><?php echo (!empty($member['birth_date']) && $member['birth_date'] !== '0000-00-00') ? date('F j, Y', strtotime($member['birth_date'])) : '—'; ?></div>
                    </div>
                    <div class="field">
                        <span class="lbl">Place of Birth</span>
                        <div class="val <?php echo empty($member['place_of_birth']) ? 'empty' : ''; ?>"><?php echo pv($member['place_of_birth'] ?? 'Not specified'); ?></div>
                    </div>

                    <div class="field-row two">
                        <div class="field">
                            <span class="lbl">Age</span>
                            <div class="val"><?php echo pv($member['age'] ?? ''); ?></div>
                        </div>
                        <div class="field">
                            <span class="lbl">Gender</span>
                            <div class="val <?php echo empty($member['gender']) ? 'empty' : ''; ?>"><?php echo pv($member['gender'] ?? 'Not specified'); ?></div>
                        </div>
                    </div>

                    <div class="field-row two">
                        <div class="field">
                            <span class="lbl">Civil Status</span>
                            <div class="val <?php echo empty($member['civil_status']) ? 'empty' : ''; ?>"><?php echo pv($member['civil_status'] ?? 'Not specified'); ?></div>
                        </div>
                        <div class="field">
                            <span class="lbl">Religion</span>
                            <div class="val <?php echo empty($member['religion']) ? 'empty' : ''; ?>"><?php echo pv($member['religion'] ?? 'Not specified'); ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- II -->
        <div class="section">
            <div class="section-head">
                <span class="num-badge">II</span>
                <h2>Address Information</h2>
            </div>
            <div class="section-body">
                <div class="fields">
                    <div class="field">
                        <span class="lbl">Province</span>
                        <div class="val <?php echo empty($member['province']) ? 'empty' : ''; ?>"><?php echo pv($member['province'] ?? 'Not specified'); ?></div>
                    </div>
                    <div class="field">
                        <span class="lbl">City / Municipality</span>
                        <div class="val <?php echo empty($member['city']) ? 'empty' : ''; ?>"><?php echo pv($member['city'] ?? 'Not specified'); ?></div>
                    </div>
                    <div class="field-row two">
                        <div class="field">
                            <span class="lbl">Barangay</span>
                            <div class="val <?php echo empty($member['barangay']) ? 'empty' : ''; ?>"><?php echo pv($member['barangay'] ?? 'Not specified'); ?></div>
                        </div>
                        <div class="field">
                            <span class="lbl">Street / Purok</span>
                            <div class="val <?php echo empty($member['street']) ? 'empty' : ''; ?>"><?php echo pv($member['street'] ?? 'Not specified'); ?></div>
                        </div>
                    </div>

                    <div class="field-group">
                        <div class="field-group-label">Addresses (editable)</div>
                        <div class="field">
                            <span class="lbl">Present Address</span>
                            <input type="text" name="present_address" class="val"
                                   value="<?php echo htmlspecialchars($member['present_address'] ?? ''); ?>"
                                   placeholder="Enter your present address">
                        </div>
                        <div class="field">
                            <span class="lbl">Permanent Address</span>
                            <input type="text" name="permanent_address" class="val"
                                   value="<?php echo htmlspecialchars($member['permanent_address'] ?? ''); ?>"
                                   placeholder="Enter your permanent address">
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- III -->
        <div class="section">
            <div class="section-head">
                <span class="num-badge">III</span>
                <h2>Contact Information</h2>
            </div>
            <div class="section-body">
                <div class="fields">
                    <div class="field">
                        <span class="lbl">Contact Number</span>
                        <input type="text" name="contact_number" class="val"
                               value="<?php echo htmlspecialchars($member['contact_number'] ?? ''); ?>"
                               placeholder="09XX XXX XXXX" inputmode="tel">
                    </div>
                    <div class="field">
                        <span class="lbl">Alternate Number</span>
                        <input type="text" name="alternate_number" class="val"
                               value="<?php echo htmlspecialchars($member['alternate_number'] ?? ''); ?>"
                               placeholder="Optional" inputmode="tel">
                    </div>
                    <div class="field">
                        <span class="lbl">Email Address</span>
                        <input type="email" name="email" class="val"
                               value="<?php echo htmlspecialchars($member['email'] ?? ''); ?>"
                               placeholder="you@example.com" inputmode="email"
                               autocomplete="email">
                    </div>
                </div>
            </div>
        </div>

        <!-- IV -->
        <div class="section">
            <div class="section-head">
                <span class="num-badge">IV</span>
                <h2>Family Background</h2>
            </div>
            <div class="section-body">
                <div class="fields">
                    <div class="field-group">
                        <div class="field-group-label">Father's Full Name</div>
                        <div class="field">
                            <span class="lbl">First Name</span>
                            <div class="val <?php echo empty($member['father_fname']) ? 'empty' : ''; ?>"><?php echo pv($member['father_fname'] ?? ''); ?></div>
                        </div>
                        <div class="field">
                            <span class="lbl">Middle Name</span>
                            <div class="val <?php echo empty($member['father_mname']) ? 'empty' : ''; ?>"><?php echo pv($member['father_mname'] ?? ''); ?></div>
                        </div>
                        <div class="field">
                            <span class="lbl">Last Name</span>
                            <div class="val <?php echo empty($member['father_lname']) ? 'empty' : ''; ?>"><?php echo pv($member['father_lname'] ?? ''); ?></div>
                        </div>
                    </div>

                    <div class="field-group">
                        <div class="field-group-label">Mother's Maiden Name</div>
                        <div class="field">
                            <span class="lbl">First Name</span>
                            <div class="val <?php echo empty($member['mother_fname']) ? 'empty' : ''; ?>"><?php echo pv($member['mother_fname'] ?? ''); ?></div>
                        </div>
                        <div class="field">
                            <span class="lbl">Middle Name</span>
                            <div class="val <?php echo empty($member['mother_mname']) ? 'empty' : ''; ?>"><?php echo pv($member['mother_mname'] ?? ''); ?></div>
                        </div>
                        <div class="field">
                            <span class="lbl">Last Name</span>
                            <div class="val <?php echo empty($member['mother_lname']) ? 'empty' : ''; ?>"><?php echo pv($member['mother_lname'] ?? ''); ?></div>
                        </div>
                    </div>

                    <div class="field-group">
                        <div class="field-group-label">Spouse (If Married)</div>
                        <div class="field">
                            <span class="lbl">First Name</span>
                            <div class="val <?php echo empty($member['spouse_fname']) ? 'empty' : ''; ?>"><?php echo pv($member['spouse_fname'] ?? ''); ?></div>
                        </div>
                        <div class="field">
                            <span class="lbl">Middle Name</span>
                            <div class="val <?php echo empty($member['spouse_mname']) ? 'empty' : ''; ?>"><?php echo pv($member['spouse_mname'] ?? ''); ?></div>
                        </div>
                        <div class="field">
                            <span class="lbl">Last Name</span>
                            <div class="val <?php echo empty($member['spouse_lname']) ? 'empty' : ''; ?>"><?php echo pv($member['spouse_lname'] ?? ''); ?></div>
                        </div>
                        <div class="field">
                            <span class="lbl">Age</span>
                            <div class="val <?php echo empty($member['spouse_age']) ? 'empty' : ''; ?>"><?php echo pv($member['spouse_age'] ?? ''); ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- V -->
        <div class="section">
            <div class="section-head">
                <span class="num-badge">V</span>
                <h2>Children</h2>
            </div>
            <div class="section-body">
                <div class="fields">
                    <?php
                    $has_children = false;
                    for ($i = 1; $i <= 4; $i++) {
                        if (!empty($member["child{$i}_fname"]) || !empty($member["child{$i}_lname"])) {
                            $has_children = true; break;
                        }
                    }
                    if (!$has_children): ?>
                        <div class="empty-block">No children listed.</div>
                    <?php else:
                        for ($i = 1; $i <= 4; $i++):
                            $cfn = $member["child{$i}_fname"] ?? '';
                            $cmn = $member["child{$i}_mname"] ?? '';
                            $cln = $member["child{$i}_lname"] ?? '';
                            $cag = $member["child{$i}_age"] ?? '';
                            if (empty($cfn) && empty($cln)) continue;
                    ?>
                        <div class="field-group">
                            <div class="field-group-label">Child <?php echo $i; ?></div>
                            <div class="field-row two">
                                <div class="field">
                                    <span class="lbl">First Name</span>
                                    <div class="val"><?php echo pv($cfn); ?></div>
                                </div>
                                <div class="field">
                                    <span class="lbl">Middle Name</span>
                                    <div class="val <?php echo empty($cmn) ? 'empty' : ''; ?>"><?php echo pv($cmn); ?></div>
                                </div>
                            </div>
                            <div class="field-row two">
                                <div class="field">
                                    <span class="lbl">Last Name</span>
                                    <div class="val"><?php echo pv($cln); ?></div>
                                </div>
                                <div class="field">
                                    <span class="lbl">Age</span>
                                    <div class="val <?php echo empty($cag) ? 'empty' : ''; ?>"><?php echo pv($cag); ?></div>
                                </div>
                            </div>
                        </div>
                    <?php endfor; endif; ?>
                </div>
            </div>
        </div>

        <!-- VI -->
        <div class="section">
            <div class="section-head">
                <span class="num-badge">VI</span>
                <h2>Character References</h2>
            </div>
            <div class="section-body">
                <div class="fields">
                    <div class="field-group">
                        <div class="field-group-label">Reference 1</div>
                        <div class="field">
                            <span class="lbl">Name</span>
                            <div class="val <?php echo empty($member['ref1_name']) ? 'empty' : ''; ?>"><?php echo pv($member['ref1_name'] ?? ''); ?></div>
                        </div>
                        <div class="field">
                            <span class="lbl">Contact Number</span>
                            <div class="val <?php echo empty($member['ref1_contact']) ? 'empty' : ''; ?>"><?php echo pv($member['ref1_contact'] ?? ''); ?></div>
                        </div>
                    </div>
                    <div class="field-group">
                        <div class="field-group-label">Reference 2</div>
                        <div class="field">
                            <span class="lbl">Name</span>
                            <div class="val <?php echo empty($member['ref2_name']) ? 'empty' : ''; ?>"><?php echo pv($member['ref2_name'] ?? ''); ?></div>
                        </div>
                        <div class="field">
                            <span class="lbl">Contact Number</span>
                            <div class="val <?php echo empty($member['ref2_contact']) ? 'empty' : ''; ?>"><?php echo pv($member['ref2_contact'] ?? ''); ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- VII -->
        <div class="section">
            <div class="section-head">
                <span class="num-badge">VII</span>
                <h2>Beneficiary Information</h2>
            </div>
            <div class="section-body">
                <div class="fields">
                    <div class="field">
                        <span class="lbl">Full Name</span>
                        <div class="val <?php echo empty($member['beneficiary_name']) ? 'empty' : ''; ?>"><?php echo pv($member['beneficiary_name'] ?? 'Not set'); ?></div>
                    </div>
                    <div class="field-row two">
                        <div class="field">
                            <span class="lbl">Relationship</span>
                            <div class="val <?php echo empty($member['beneficiary_relation']) ? 'empty' : ''; ?>"><?php echo pv($member['beneficiary_relation'] ?? ''); ?></div>
                        </div>
                        <div class="field">
                            <span class="lbl">Age</span>
                            <div class="val <?php echo empty($member['beneficiary_age']) ? 'empty' : ''; ?>"><?php echo pv($member['beneficiary_age'] ?? ''); ?></div>
                        </div>
                    </div>
                    <div class="field">
                        <span class="lbl">Address</span>
                        <div class="val <?php echo empty($member['beneficiary_address']) ? 'empty' : ''; ?>"><?php echo pv($member['beneficiary_address'] ?? ''); ?></div>
                    </div>
                    <div class="field">
                        <span class="lbl">Contact Number</span>
                        <div class="val <?php echo empty($member['beneficiary_contact']) ? 'empty' : ''; ?>"><?php echo pv($member['beneficiary_contact'] ?? ''); ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- VIII -->
        <div class="section">
            <div class="section-head">
                <span class="num-badge">VIII</span>
                <h2>Chapter Information</h2>
            </div>
            <div class="section-body">
                <div class="fields">
                    <div class="field-row two">
                        <div class="field">
                            <span class="lbl">Chapter</span>
                            <div class="val <?php echo empty($member['chapter']) ? 'empty' : ''; ?>"><?php echo pv($member['chapter'] ?? 'Not assigned'); ?></div>
                        </div>
                        <div class="field">
                            <span class="lbl">Group</span>
                            <div class="val <?php echo empty($member['group_name']) ? 'empty' : ''; ?>"><?php echo pv($member['group_name'] ?? 'Not assigned'); ?></div>
                        </div>
                    </div>
                    <div class="field-row two">
                        <div class="field">
                            <span class="lbl">Leader</span>
                            <div class="val <?php echo empty($member['leader']) ? 'empty' : ''; ?>"><?php echo pv($member['leader'] ?? 'Not assigned'); ?></div>
                        </div>
                        <div class="field">
                            <span class="lbl">Coordinator</span>
                            <div class="val <?php echo empty($member['coordinator']) ? 'empty' : ''; ?>"><?php echo pv($member['coordinator'] ?? 'Not assigned'); ?></div>
                        </div>
                    </div>
                    <div class="field">
                        <span class="lbl">Chairman</span>
                        <div class="val <?php echo empty($member['chairman']) ? 'empty' : ''; ?>"><?php echo pv($member['chairman'] ?? 'Not assigned'); ?></div>
                    </div>
                    <div class="field-row two">
                        <div class="field">
                            <span class="lbl">Screening Officer</span>
                            <div class="val <?php echo empty($member['screening_officer']) ? 'empty' : ''; ?>"><?php echo pv($member['screening_officer'] ?? 'Not assigned'); ?></div>
                        </div>
                        <div class="field">
                            <span class="lbl">Screening Date</span>
                            <div class="val <?php echo empty($member['screening_date']) ? 'empty' : ''; ?>"><?php echo !empty($member['screening_date']) ? date('F j, Y', strtotime($member['screening_date'])) : 'Not set'; ?></div>
                        </div>
                    </div>
                    <div class="field-row two">
                        <div class="field">
                            <span class="lbl">Approved By</span>
                            <div class="val <?php echo empty($member['approved_by']) ? 'empty' : ''; ?>"><?php echo pv($member['approved_by'] ?? 'Not assigned'); ?></div>
                        </div>
                        <div class="field">
                            <span class="lbl">Date Registered</span>
                            <div class="val <?php echo empty($member['date_registered']) ? 'empty' : ''; ?>"><?php echo !empty($member['date_registered']) ? date('F j, Y', strtotime($member['date_registered'])) : 'Not set'; ?></div>
                        </div>
                    </div>
                    <div class="field">
                        <span class="lbl">Membership Status</span>
                        <div class="val"><span class="chip <?php echo $status_class; ?>"><?php echo ucfirst($status); ?></span></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- IX -->
        <div class="section">
            <div class="section-head">
                <span class="num-badge">IX</span>
                <h2>Account Information</h2>
            </div>
            <div class="section-body">
                <div class="fields">
                    <div class="field">
                        <span class="lbl">Username</span>
                        <div class="val"><?php echo pv($member['username'] ?? 'Not set'); ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Actions -->
        <div class="actions">
            <button type="submit" class="btn-primary">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                Save changes
            </button>
            <button type="button" class="btn-secondary" id="printBtn">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                Print / Export PDF
            </button>
        </div>
    </form>

</main>

<!-- Bottom Nav (5 Tabs) -->
<nav class="bottom-nav">
    <a href="dashboard.php" class="nav-item">
        <span class="icon">🏠</span>
        <span>Home</span>
    </a>
    <a href="notifications.php" class="nav-item">
        <span class="icon">🔔</span>
        <span>Notify</span>
    </a>
    <a href="payments.php" class="nav-item">
        <span class="icon">💳</span>
        <span>Payments</span>
    </a>
    <a href="profile.php" class="nav-item active">
        <span class="icon">👤</span>
        <span>Profile</span>
    </a>
    <a href="support.php" class="nav-item">
        <span class="icon">❓</span>
        <span>Help</span>
    </a>
</nav>

<script>
// Drawer Logic
(function(){
    const openBtn = document.getElementById('openDrawer');
    const closeBtn = document.getElementById('closeDrawer');
    const drawer = document.getElementById('sideDrawer');
    const overlay = document.getElementById('drawerOverlay');

    function openDrawer() {
        drawer.classList.add('open');
        overlay.classList.add('open');
    }

    function closeDrawer() {
        drawer.classList.remove('open');
        overlay.classList.remove('open');
    }

    openBtn.addEventListener('click', openDrawer);
    closeBtn.addEventListener('click', closeDrawer);
    overlay.addEventListener('click', closeDrawer);
})();

// Profile Photo Upload Logic
(function(){
    const uploadBtn      = document.getElementById('uploadPhotoBtn');
    const photoInput     = document.getElementById('photoInput');
    const photoContainer = document.getElementById('profilePhotoContainer');
    const progressWrap   = document.getElementById('uploadProgress');
    const progressFill   = progressWrap.querySelector('.bar-fill');

    if (!uploadBtn || !photoInput) return;
    uploadBtn.addEventListener('click', () => photoInput.click());

    photoInput.addEventListener('change', function(){
        const file = this.files[0];
        if (!file) return;

        const validTypes = ['image/jpeg','image/png','image/gif','image/webp'];
        if (!validTypes.includes(file.type)) {
            alert('Invalid file type. Please select a JPG, PNG, GIF, or WEBP image.');
            this.value = '';
            return;
        }
        if (file.size > 5 * 1024 * 1024) {
            alert('File size must be less than 5MB.');
            this.value = '';
            return;
        }

        const reader = new FileReader();
        reader.onload = e => {
            photoContainer.innerHTML = `<img src="${e.target.result}" alt="Profile photo">`;
        };
        reader.readAsDataURL(file);

        const fd = new FormData();
        fd.append('csrf_token', '<?php echo $csrf_token; ?>');
        fd.append('photo', file);

        progressWrap.classList.add('show');
        let pct = 0;
        const tick = setInterval(() => {
            pct = Math.min(95, pct + 10);
            progressFill.style.width = pct + '%';
        }, 100);

        fetch('upload_member_photo.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(r => r.json())
        .then(data => {
            clearInterval(tick);
            progressFill.style.width = '100%';
            setTimeout(() => {
                progressWrap.classList.remove('show');
                if (data.success) {
                    const a = document.createElement('div');
                    a.className = 'alert alert-success';
                    a.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg><span>' + (data.message || 'Photo submitted. Awaiting approval.') + '</span>';
                    document.querySelector('.main').insertBefore(a, document.querySelector('.main').children[0]);
                    setTimeout(() => location.reload(), 1800);
                } else {
                    alert(data.message || 'Upload failed.');
                    location.reload();
                }
            }, 400);
        })
        .catch(err => {
            clearInterval(tick);
            progressWrap.classList.remove('show');
            alert('Upload error: ' + err.message);
            location.reload();
        });
    });
})();

document.getElementById('printBtn').addEventListener('click', () => window.print());
</script>

</body>
</html>