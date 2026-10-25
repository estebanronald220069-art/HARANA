<?php
// user/payments.php — Phone-first redesign (2026-10)
require_once '../includes/config.php';
require_once '../includes/db_connection.php';
require_once '../includes/security.php';
require_once '../includes/auth.php';
require_once '../includes/user_functions.php';

if (!isset($auth) || !($auth instanceof Auth)) {
    $auth = new Auth();
}

$auth->requireLogin();
$current_user = $auth->getCurrentUser();

$db = Database::getInstance();
$member = getUserMemberData($db, $current_user);

if (!$member || empty($member['member_code'])) {
    $member = $db->getSingle(
        "SELECT * FROM members WHERE username = ? OR email = ?",
        [$current_user['username'] ?? '', $current_user['email'] ?? ''],
        'ss'
    );
}

if (!$member || empty($member['member_code'])) {
    header('Location: dashboard.php?error=member_not_found');
    exit();
}

$member_code = $member['member_code'];

$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$limit = 20;
$offset = ($page - 1) * $limit;

// FIX: Direct SQL queries to bypass broken getUserPayments() and ensure member_code is used.
$payments = $db->getAll(
    "SELECT * FROM payments 
     WHERE member_code = ? 
     ORDER BY payment_date DESC, payment_id DESC 
     LIMIT ? OFFSET ?",
    [$member_code, $limit, $offset], 'sii'
);

$total_payments = (int)($db->getSingle(
    "SELECT COUNT(*) as total FROM payments WHERE member_code = ?",
    [$member_code], 's'
)['total'] ?? 0);

$total_pages = $limit > 0 ? ceil($total_payments / $limit) : 1;

// ── Last confirmed payment date ────────────────────────────────
$payment_summary = $db->getSingle(
    "SELECT MAX(payment_date) AS last_payment
     FROM payments
     WHERE member_code = ? AND payment_status = 'confirmed'",
    [$member_code], 's'
);

// ── Pending GCash uploads for this member ──────────────────────
$pending_count = $db->getSingle(
    "SELECT COUNT(*) AS count
     FROM payments
     WHERE member_code = ? AND payment_status = 'pending'",
    [$member_code], 's'
)['count'] ?? 0;

$balance = getUserBalance($db, $member_code);
$months_as_member = calculateMonthsAsMember($member);
$monthly_contribution = $member['monthly_contribution'] ?? 100;
$expected_total = $months_as_member * $monthly_contribution;
$total_paid = $balance['total_paid'] ?? 0;
$current_balance = $expected_total - $total_paid;

// ---------- Next due date ----------
$last_payment_date = $payment_summary['last_payment'] ?? $member['date_joined'] ?? date('Y-m-d');
$next_due_date     = date('Y-m-d', strtotime($last_payment_date . ' +1 month'));

$today          = new DateTime();
$next_due_obj   = new DateTime($next_due_date);
$days_until_due = (int)$today->diff($next_due_obj)->format('%r%a');
$is_overdue     = $days_until_due < 0;
$is_due_soon    = $days_until_due >= 0 && $days_until_due <= 7;

$has_pending = $pending_count > 0;
$csrf_token = Security::generateCSRFToken();

$success_message = isset($_GET['success']) ? $_GET['success'] : '';
$success_text = [
    'gcash_submitted'  => 'Your GCash payment has been submitted. An administrator will verify your screenshot and reference number shortly. You will receive a notification once confirmed.',
    'gcash_confirmed'  => 'Your GCash payment has been confirmed.',
    'payment_submitted'=> 'Your payment has been submitted.',
][$success_message] ?? null;
$error_message = isset($_GET['error']) ? $_GET['error'] : '';

$is_admin = ($current_user['role'] === 'admin');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=2.0, user-scalable=yes">
<title>Smart Pay — <?php echo defined('APP_NAME') ? APP_NAME : 'NHGL'; ?></title>
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
.header-actions{display:flex;gap:8px;flex-shrink:0;align-items:center}
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
.alert svg{width:20px;height:20px;flex-shrink:0;margin-top:1px}

/* CTA Banner */
.pay-cta{
    background:linear-gradient(135deg, #007dfe 0%, #005fcc 100%);
    color:#fff;border-radius:var(--radius-lg);padding:1.25rem 1.15rem;
    margin-bottom:1rem;position:relative;overflow:hidden;
    box-shadow:var(--shadow-lg);display:flex;flex-direction:column;gap:12px;
}
.pay-cta.pending{
    background:linear-gradient(135deg,#78350f 0%,#b45309 100%);
}
.pay-cta::before{
    content:'';position:absolute;top:-40px;right:-40px;width:180px;height:180px;
    background:radial-gradient(circle,rgba(255,255,255,0.15) 0%,transparent 70%);
    border-radius:50%;pointer-events:none;
}
.pay-cta h3{font-size:1.15rem;font-weight:800;margin:0 0 4px;display:flex;align-items:center;gap:8px;position:relative;z-index:2}
.pay-cta p{font-size:0.85rem;margin:0;opacity:0.95;line-height:1.5;position:relative;z-index:2}

.btn-pay-main{
    background:#86efac;
    color:#14532d;
    border:none;
    padding:0.85rem 1rem;border-radius:var(--radius);font-size:1rem;font-weight:800;
    font-family:inherit;cursor:pointer;transition:all 0.18s;text-decoration:none;
    display:inline-flex;align-items:center;justify-content:center;gap:10px;
    width:100%;min-height:48px;box-shadow:0 4px 12px rgba(0,0,0,0.15);position:relative;z-index:2;
}
.btn-pay-main:hover{
    transform:translateY(-2px);
    background:#4ade80;
    box-shadow:0 6px 16px rgba(0,0,0,0.2);
}
.btn-pay-disabled{
    background:rgba(255,255,255,0.2);color:rgba(255,255,255,0.7);
    pointer-events:none;box-shadow:none;
}

/* Stats Strip */
.stats-strip{
    background:var(--surface);border:1px solid var(--line);border-radius:var(--radius);
    display:flex;flex-wrap:wrap;margin-bottom:1.5rem;box-shadow:var(--shadow-sm);
}
.stat-box{
    flex:1;min-width:50%;padding:1rem;display:flex;flex-direction:column;gap:2px;
    border-bottom:1px solid var(--line-soft);
}
.stat-box:nth-child(odd){border-right:1px solid var(--line-soft)}
.stat-box:nth-child(3),.stat-box:nth-child(4){border-bottom:none}
.stat-box .lbl{font-size:0.7rem;font-weight:800;color:var(--ink-500);text-transform:uppercase;letter-spacing:0.05em}
.stat-box .val{font-size:1.1rem;font-weight:900;color:var(--ink-900);line-height:1.2}
.stat-box .val small{font-size:0.8rem;font-weight:600;color:var(--ink-500)}

/* Section */
.section-title{font-size:0.85rem;text-transform:uppercase;letter-spacing:0.05em;
    font-weight:800;color:var(--ink-500);margin:1.5rem 0 0.75rem;padding:0 4px;
    display:flex;align-items:center;gap:8px}
.section-title::before{content:'';display:inline-block;width:4px;height:14px;
    background:var(--yellow-accent);border-radius:2px}

/* Formal List Layout */
.statement-list{
    background:var(--surface);border:1px solid var(--line);border-radius:var(--radius);
    box-shadow:var(--shadow-sm);overflow:hidden;
}
.statement-item{
    padding:1rem;border-bottom:1px solid var(--line-soft);
    display:flex;flex-direction:column;gap:6px;
}
.statement-item:last-child{border-bottom:none}
.st-top{display:flex;justify-content:space-between;align-items:flex-start}
.st-amt{font-size:1.15rem;font-weight:900;color:var(--ink-900)}
.st-date{font-size:0.8rem;color:var(--ink-500);font-weight:600}
.st-status{
    display:inline-flex;align-items:center;gap:4px;padding:3px 8px;border-radius:6px;
    font-size:0.7rem;font-weight:800;text-transform:uppercase;letter-spacing:0.02em;
}
.st-status.confirmed{background:var(--success-100);color:var(--success-700)}
.st-status.pending{background:var(--warning-100);color:var(--warning-700)}
.st-status.failed{background:var(--danger-100);color:var(--danger-700)}
.st-meta{
    display:flex;flex-wrap:wrap;gap:8px;font-size:0.8rem;color:var(--ink-500);font-weight:500;
}
.st-meta span{display:inline-flex;align-items:center;gap:4px}
.st-ref{
    font-family:'Courier New',monospace;background:var(--paper);padding:1px 5px;
    border-radius:4px;font-weight:700;color:var(--ink-700);font-size:0.75rem;
}

/* Empty State */
.empty-box{
    text-align:center;padding:2.5rem 1rem;border:2px dashed var(--line);border-radius:var(--radius-lg);
}
.empty-box svg{width:40px;height:40px;color:var(--ink-300);margin-bottom:12px}
.empty-box h3{font-size:1.05rem;font-weight:800;margin:0 0 4px}
.empty-box p{font-size:0.9rem;color:var(--ink-500);margin:0}

/* Pagination */
.pagination{display:flex;justify-content:center;gap:6px;margin-top:1.5rem;flex-wrap:wrap}
.page-link{
    padding:8px 12px;background:var(--surface);border:1px solid var(--line);
    border-radius:var(--radius-sm);color:var(--brand-700);font-weight:700;font-size:0.85rem;
    text-decoration:none;transition:all 0.15s;display:inline-flex;align-items:center;justify-content:center;min-width:40px;
}
.page-link:hover:not(.disabled){background:var(--brand-50);border-color:var(--brand-500)}
.page-link.active{background:var(--brand-900);color:#fff;border-color:var(--brand-900)}
.page-link.disabled{color:var(--ink-300);pointer-events:none;background:var(--paper)}

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

/* Responsive Layout for Desktop */
@media (min-width:700px){
    .main{padding-bottom:2rem; max-width:640px;}
    .bottom-nav{position:static;max-width:640px;margin:0 auto 2rem;border-radius:var(--radius);border:1px solid var(--line);box-shadow:var(--shadow-sm)}
    .bottom-nav .nav-item{font-size:0.75rem}
    .pay-cta{flex-direction:row;align-items:center;justify-content:space-between; padding:1.5rem;}
    .pay-cta .btn-pay-main{width:auto;min-width:220px;}
    .stats-strip{flex-wrap:nowrap;}
    .stat-box{border-bottom:none !important;border-right:1px solid var(--line-soft);}
    .stat-box:last-child{border-right:none;}
    .statement-item{padding:1.25rem 1.5rem;}
}
@media (max-width:540px){
    html{font-size:17px}
    .main{padding:0.85rem 0.75rem 6rem}
    .app-brand .brand-sub{display:none}
    .bottom-nav .nav-item{font-size:0.65rem}
    .bottom-nav .nav-item .icon{font-size:1.25rem}
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
            <img src="../assets/images/harana-logo.png" alt="NHGL" onerror="this.style.display='none';this.parentNode.textContent='NH';">
        </div>
        <div class="brand-text">
            <span class="brand-name">NHGL</span>
            <span class="brand-sub">Nagkaisang Haranista</span>
        </div>
    </div>
    <div class="header-actions">
        <button class="icon-btn" id="openDrawer" aria-label="Menu">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="1"/><circle cx="12" cy="5" r="1"/><circle cx="12" cy="19" r="1"/></svg>
        </button>
    </div>
</header>

<main class="main">
    <?php if (!empty($success_text)): ?>
        <div class="alert alert-success">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
            <span><?php echo htmlspecialchars($success_text); ?></span>
        </div>
    <?php endif; ?>

    <?php if ($error_message == 'payment_failed'): ?>
        <div class="alert alert-danger">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <span><strong>Payment failed.</strong> Please try again or contact support.</span>
        </div>
    <?php endif; ?>

    <!-- CTA Banner (GCash Blue) -->
    <div class="pay-cta <?php echo $has_pending ? 'pending' : ''; ?>">
        <div>
            <?php if ($has_pending): ?>
                <h3><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="width:20px;height:20px;"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg> Payment Under Review</h3>
                <p>Your recent payment is pending admin verification. You may submit a new payment once it is confirmed.</p>
            <?php else: ?>
                <h3>Contribution</h3>
                <p>Pay your monthly contribution securely using GCash.</p>
            <?php endif; ?>
        </div>

        <?php if ($has_pending): ?>
            <span class="btn-pay-main btn-pay-disabled">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="width:18px;height:18px;"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
                Pending Review
            </span>
        <?php else: ?>
            <a href="gcash_payment.php" class="btn-pay-main">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="width:18px;height:18px;"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
                Pay with GCash
            </a>
        <?php endif; ?>
    </div>

    <!-- Stats Strip -->
    <div class="stats-strip">
        <div class="stat-box">
            <div class="lbl">Last Payment</div>
            <div class="val"><?php echo !empty($payment_summary['last_payment']) ? date('M d, Y', strtotime($payment_summary['last_payment'])) : 'None'; ?></div>
        </div>
        <div class="stat-box">
            <div class="lbl">Next Due</div>
            <div class="val" style="color:<?php echo $has_pending ? 'var(--warning-700)' : ($is_overdue ? 'var(--danger-700)' : 'var(--ink-900)'); ?>;">
                <?php echo date('M d, Y', strtotime($next_due_date)); ?>
            </div>
        </div>
        <div class="stat-box">
            <div class="lbl">Monthly Due</div>
            <div class="val">₱<?php echo number_format($monthly_contribution, 2); ?></div>
        </div>
        <div class="stat-box">
            <div class="lbl">Months Active</div>
            <div class="val"><?php echo $months_as_member; ?> <small>mos</small></div>
        </div>
    </div>

    <!-- Payment History -->
    <div class="section-title">Payment Statement</div>

    <?php if (empty($payments)): ?>
        <div class="empty-box">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
            <h3>No payment history yet</h3>
            <p>You have not made any payments so far.</p>
        </div>
    <?php else: ?>
        <div class="statement-list">
            <?php foreach ($payments as $payment): 
                $display_period = !empty($payment['period_month']) ? $payment['period_month'] : date('Y-m', strtotime($payment['payment_date']));
            ?>
            <div class="statement-item">
                <div class="st-top">
                    <div>
                        <div class="st-amt">₱<?php echo number_format($payment['amount'], 2); ?></div>
                        <div class="st-date"><?php echo date('M d, Y · h:i A', strtotime($payment['payment_date'])); ?></div>
                    </div>
                    <span class="st-status <?php echo $payment['payment_status']; ?>">
                        <?php echo ucfirst($payment['payment_status']); ?>
                    </span>
                </div>

                <div class="st-meta">
                    <span><?php echo ucfirst($payment['payment_method']); ?></span>
                    <span>· Period: <?php echo date('M Y', strtotime($display_period . '-01')); ?></span>
                    <?php if (!empty($payment['receipt_number'])): ?>
                        <span>· Receipt: <span class="st-ref"><?php echo htmlspecialchars($payment['receipt_number']); ?></span></span>
                    <?php endif; ?>
                    <?php if (!empty($payment['gcash_reference'])): ?>
                        <span>· Ref: <span class="st-ref"><?php echo htmlspecialchars($payment['gcash_reference']); ?></span></span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
        <div class="pagination">
            <a href="?page=1" class="page-link <?php echo $page <= 1 ? 'disabled' : ''; ?>">&laquo;</a>
            <a href="?page=<?php echo max(1, $page - 1); ?>" class="page-link <?php echo $page <= 1 ? 'disabled' : ''; ?>">&lsaquo;</a>
            <?php
            $start = max(1, $page - 2);
            $end = min($total_pages, $page + 2);
            for ($i = $start; $i <= $end; $i++):
            ?>
                <a href="?page=<?php echo $i; ?>" class="page-link <?php echo $i == $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
            <?php endfor; ?>
            <a href="?page=<?php echo min($total_pages, $page + 1); ?>" class="page-link <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">&rsaquo;</a>
            <a href="?page=<?php echo $total_pages; ?>" class="page-link <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">&raquo;</a>
        </div>
        <?php endif; ?>
    <?php endif; ?>
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
    <a href="payments.php" class="nav-item active">
        <span class="icon">💳</span>
        <span>Payments</span>
    </a>
    <a href="profile.php" class="nav-item">
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
</script>

</body>
</html>