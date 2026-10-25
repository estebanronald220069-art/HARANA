<?php
// user/support.php — Phone-first redesign (2026-10)
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
    $member_code = null;
    $member_name = $current_user['full_name'] ?? '';
    $member_email = $current_user['email'] ?? '';
} else {
    $member_code = $member['member_code'];
    $member_name = ($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? '');
    $member_email = $member['email'] ?? $current_user['email'] ?? '';
}

$message = '';
$error = '';

// Handle contact form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_message'])) {
    if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token';
    } else {
        $name = Security::sanitize($_POST['name'] ?? '');
        $email = Security::sanitize($_POST['email'] ?? '');
        $subject = Security::sanitize($_POST['subject'] ?? '');
        $category = Security::sanitize($_POST['category'] ?? '');
        $user_message = Security::sanitize($_POST['message'] ?? '');
        
        if (empty($name) || empty($email) || empty($subject) || empty($category) || empty($user_message)) {
            $error = 'Please fill in all required fields.';
        } else {
            $insert = $db->execute(
                "INSERT INTO support_messages (user_id, member_code, name, email, subject, category, message, status, created_at) 
                 VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', NOW())",
                [$current_user['user_id'], $member_code, $name, $email, $subject, $category, $user_message],
                'issssss'
            );
            
            if ($insert) {
                $message = 'Your message has been sent successfully! We will respond within 24-48 hours.';
                $_POST = [];
            } else {
                $error = 'Failed to send message. Please try again.';
            }
        }
    }
}

// Get user's previous messages
$user_messages = $db->getAll(
    "SELECT * FROM support_messages 
     WHERE user_id = ? 
     ORDER BY created_at DESC LIMIT 10",
    [$current_user['user_id']], 'i'
);

// Get FAQs from database
$faqs = $db->getAll(
    "SELECT * FROM faqs 
     WHERE is_active = 1 
     ORDER BY display_order ASC, faq_id ASC"
);

// If no FAQs in database, seed with default ones
if (empty($faqs)) {
    $default_faqs = [
        ['category' => 'Membership', 'question' => 'How do I become a member?', 'answer' => 'To become a member, you need to fill out the membership application form, submit a medical certificate (original copy), birth certificate copy (if 45 years and above), and pay the membership fee of ₱100.00 plus ID fee of ₱100.00.'],
        ['category' => 'Membership', 'question' => 'What is the age requirement for membership?', 'answer' => 'Regular members must be between 10-60 years old. Beneficiaries who become members must be 10-60 years old and are limited to legitimate family members of deceased members.'],
        ['category' => 'Payments', 'question' => 'How do I make my monthly contribution?', 'answer' => 'You can pay your monthly contribution through: 1) Cash payments at our office (MF 2024, Brgy. Singalat, Palayan City), 2) GCash (0917 123 4567), or 3) Bank Transfer (BDO Account # 1234-5678-90).'],
        ['category' => 'Payments', 'question' => 'What is the monthly contribution amount?', 'answer' => 'The monthly contribution is ₱100.00. You also pay a monthly "butaw" (due) of ₱10.00, and ₱20.00 for death benefit fund per deceased member.'],
        ['category' => 'Benefits', 'question' => 'What are the death benefits?', 'answer' => 'Death benefits vary by membership duration: 1-2 years: ₱80,000, 3-4 years: ₱90,000, 5-6 years: ₱100,000, up to ₱150,000 for 16+ years. Additional ₱10,000 for accidental death and ₱5,000 for good payers (5+ years no missed payments).'],
        ['category' => 'Benefits', 'question' => 'What is the Damayan program?', 'answer' => 'Damayan is our mutual aid program providing emergency financial assistance for medical emergencies (₱10,000), hospitalization (₱15,000), calamity assistance (₱5,000), and burial assistance (₱20,000) for active members.'],
        ['category' => 'Beneficiary', 'question' => 'How do I update my beneficiary?', 'answer' => 'To update your beneficiary, go to the Beneficiary page in your dashboard, fill out the form with the new beneficiary details, upload supporting documents (birth certificate, marriage certificate, valid ID), and submit. Updates require admin approval within 3-5 business days.'],
        ['category' => 'Beneficiary', 'question' => 'Who can be my beneficiary?', 'answer' => 'Beneficiaries must be legitimate family members (spouse, child, parent, or legal dependent) aged 10-60 years old. Only ONE beneficiary can be designated at a time.'],
        ['category' => 'General', 'question' => 'What happens if I miss a payment?', 'answer' => 'If you miss payments, penalties apply: 1-10 missed payments: deduct ₱2,500 from benefits, 11-25: deduct ₱5,000, 26-50: deduct ₱10,000, 51-70: half of death benefit, 71+: donation based on membership duration.'],
        ['category' => 'General', 'question' => 'What is dual membership?', 'answer' => 'Dual membership means being a member of other organizations besides NHGL. Dual members are not eligible for full benefits and do not have the same privileges as loyal members.'],
    ];
    
    foreach ($default_faqs as $faq) {
        $db->execute(
            "INSERT INTO faqs (category, question, answer, is_active) VALUES (?, ?, ?, 1)",
            [$faq['category'], $faq['question'], $faq['answer']],
            'sss'
        );
    }
    
    $faqs = $db->getAll(
        "SELECT * FROM faqs WHERE is_active = 1 ORDER BY display_order ASC, faq_id ASC"
    );
}

// Group FAQs by category
$faqs_by_category = [];
foreach ($faqs as $faq) {
    $faqs_by_category[$faq['category']][] = $faq;
}

$csrf_token = Security::generateCSRFToken();
$is_admin = ($current_user['role'] === 'admin');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=2.0, user-scalable=yes">
<title>Support Center — <?php echo defined('APP_NAME') ? APP_NAME : 'NHGL'; ?></title>
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
   MAIN LAYOUT
============================================================ */
.main{max-width:720px;margin:0 auto;padding:1.25rem 1rem 6rem}

/* Alerts */
.alert{
    border-radius:var(--radius);padding:0.85rem 1rem;
    margin-bottom:0.9rem;font-weight:600;
    display:flex;align-items:flex-start;gap:10px;font-size:0.9rem;
    line-height:1.5;
}
.alert.alert-success{background:var(--success-100);border:2px solid #86efac;color:var(--success-700)}
.alert.alert-danger{background:var(--danger-100);border:2px solid #fca5a5;color:var(--danger-700)}

/* ============================================================
   CARDS (Minimized)
============================================================ */
.card{
    background:var(--surface);border:1px solid var(--line);
    border-radius:var(--radius-lg);overflow:hidden;
    box-shadow:var(--shadow-sm);margin-bottom:1rem;
}
.card-header{
    background:var(--brand-50);border-bottom:1px solid var(--line);
    padding:0.85rem 1rem;
    display:flex;align-items:center;gap:10px;
}
.card-header h2{
    font-size:0.9rem;font-weight:800;color:var(--ink-900);margin:0;
    display:flex;align-items:center;gap:8px;
}
.card-header h2 svg{color:var(--brand-600);width:18px;height:18px}
.card-body{padding:1rem}

/* ============================================================
   CONTACT INFO (Minimized)
============================================================ */
.contact-grid{
    display:grid;grid-template-columns:1fr 1fr;gap:12px;
}
.contact-box{
    background:var(--paper);padding:12px;border-radius:var(--radius-sm);
}
.contact-box h3{
    font-size:0.7rem;text-transform:uppercase;letter-spacing:0.05em;
    font-weight:800;color:var(--ink-500);margin:0 0 4px;
    display:flex;align-items:center;gap:6px;
}
.contact-box h3 svg{width:14px;height:14px;color:var(--brand-500)}
.contact-box p{
    font-size:0.85rem;font-weight:600;color:var(--ink-900);
    margin:0;line-height:1.4;
}

/* ============================================================
   FORM
============================================================ */
.form-group{margin-bottom:0.85rem}
.form-label{
    display:block;font-size:0.75rem;text-transform:uppercase;
    letter-spacing:0.05em;font-weight:800;color:var(--ink-500);
    margin-bottom:4px;
}
.form-control, .form-select{
    width:100%;border:1px solid var(--line);border-radius:var(--radius-sm);
    padding:0.7rem 0.85rem;font-size:0.95rem;font-family:inherit;
    background:var(--surface);color:var(--ink-900);outline:none;
    transition:all 0.15s;
}
.form-control:focus, .form-select:focus{
    border-color:var(--brand-500);
    box-shadow:0 0 0 3px rgba(61,122,184,0.15);
}
textarea.form-control{resize:vertical;min-height:100px}
.btn-submit{
    background:var(--yellow-accent);color:var(--brand-900);
    border:none;padding:0.85rem 1rem;border-radius:var(--radius);
    font-size:0.95rem;font-weight:800;font-family:inherit;
    cursor:pointer;transition:all 0.18s;width:100%;
    display:inline-flex;align-items:center;justify-content:center;gap:8px;
    box-shadow:0 4px 14px rgba(251,191,36,0.35);
    min-height:48px;margin-top:0.5rem;
}
.btn-submit:hover{transform:translateY(-2px);box-shadow:0 8px 20px rgba(251,191,36,0.5)}
.btn-submit svg{width:16px;height:16px}

/* ============================================================
   FAQ ACCORDION (Minimized)
============================================================ */
.category-tag{
    display:inline-block;padding:3px 10px;
    background:var(--brand-900);color:#fff;
    border-radius:100px;font-size:0.7rem;font-weight:800;
    margin-bottom:0.4rem;margin-top:0.85rem;text-transform:uppercase;
    letter-spacing:0.05em;
}
.category-tag:first-child{margin-top:0}
.faq-item{border-bottom:1px solid var(--line-soft)}
.faq-item:last-child{border-bottom:none}
.faq-question{
    padding:0.75rem 0;cursor:pointer;display:flex;
    justify-content:space-between;align-items:flex-start;gap:12px;
    font-weight:700;color:var(--ink-900);font-size:0.9rem;
    transition:color 0.15s;
}
.faq-question:hover{color:var(--brand-600)}
.faq-question svg{
    color:var(--brand-600);flex-shrink:0;
    width:16px;height:16px;margin-top:2px;
    transition:transform 0.2s;
}
.faq-answer{
    display:none;padding:0 0 0.85rem 0;
    color:var(--ink-700);font-size:0.85rem;line-height:1.5;
}
.faq-answer.show{display:block}
.faq-item.open .faq-question svg{transform:rotate(180deg)}

/* ============================================================
   MESSAGE HISTORY (Minimized)
============================================================ */
.message-item{
    background:var(--brand-50);
    border:1px solid var(--line);
    border-left:4px solid var(--brand-500);
    border-radius:var(--radius-sm);
    padding:0.85rem;margin-bottom:8px;
}
.message-item:last-child{margin-bottom:0}
.message-header{
    display:flex;justify-content:space-between;
    align-items:flex-start;gap:10px;margin-bottom:4px;
}
.message-subject{
    font-weight:800;color:var(--ink-900);font-size:0.9rem;
    line-height:1.3;
}
.message-status{
    display:inline-block;padding:2px 8px;border-radius:100px;
    font-size:0.65rem;font-weight:800;text-transform:uppercase;
    letter-spacing:0.02em;flex-shrink:0;
}
.status-pending{background:var(--warning-100);color:var(--warning-700)}
.status-read{background:var(--brand-100);color:var(--brand-700)}
.status-replied{background:var(--success-100);color:var(--success-700)}
.message-meta{
    font-size:0.75rem;color:var(--ink-500);font-weight:500;
    margin-bottom:6px;display:flex;align-items:center;gap:6px;flex-wrap:wrap;
}
.message-preview{
    font-size:0.85rem;color:var(--ink-700);line-height:1.5;
}
.message-reply{
    margin-top:8px;padding-top:8px;
    border-top:1px dashed var(--line);
    font-size:0.8rem;color:var(--success-700);font-weight:600;
    display:flex;gap:6px;align-items:flex-start;
}
.message-reply svg{width:14px;height:14px;flex-shrink:0;margin-top:2px}

/* ============================================================
   SERVICES GRID (Minimized)
============================================================ */
.services-grid{
    display:grid;grid-template-columns:1fr 1fr;gap:8px;
}
.service-item{
    text-align:center;padding:0.85rem 0.5rem;
    background:var(--paper);border-radius:var(--radius-sm);
}
.service-item svg{
    width:22px;height:22px;color:var(--brand-600);
    margin-bottom:4px;
}
.service-item h3{
    font-size:0.8rem;font-weight:800;color:var(--ink-900);
    margin:0 0 2px;
}
.service-item small{
    font-size:0.7rem;color:var(--ink-500);line-height:1.2;
    display:block;
}

/* ============================================================
   MAP (Minimized)
============================================================ */
.map-wrapper iframe{
    display:block;width:100%;height:180px;border:none;
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

/* Responsive */
@media (min-width:700px){
    .main{padding-bottom:2rem}
    .bottom-nav{
        position:static;max-width:720px;margin:0 auto 2rem;
        border-radius:var(--radius);border:1px solid var(--line);
        box-shadow:var(--shadow-sm);
    }
    .services-grid{grid-template-columns:repeat(4,1fr)}
    .bottom-nav .nav-item{font-size:0.75rem}
}
@media (max-width:540px){
    html{font-size:17px}
    .main{padding:0.85rem 0.75rem 6rem}
    .app-brand .brand-sub{display:none}
    .contact-grid{grid-template-columns:1fr}
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
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="width:20px;height:20px;flex-shrink:0;"><polyline points="20 6 9 17 4 12"/></svg>
            <span><?php echo htmlspecialchars($message); ?></span>
        </div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="width:20px;height:20px;flex-shrink:0;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <span><?php echo htmlspecialchars($error); ?></span>
        </div>
    <?php endif; ?>

    <!-- Contact Information -->
    <div class="card">
        <div class="card-header">
            <h2>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                Contact Information
            </h2>
        </div>
        <div class="card-body">
            <div class="contact-grid">
                <div class="contact-box">
                    <h3><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg> Address</h3>
                    <p>MF 2024, Brgy. Singalat, Palayan City, Nueva Ecija</p>
                </div>
                <div class="contact-box">
                    <h3><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg> Phone</h3>
                    <p>(044) 940-6708<br>0917 123 4567</p>
                </div>
                <div class="contact-box">
                    <h3><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg> Email</h3>
                    <p>info@harana.org.ph<br>support@harana.org.ph</p>
                </div>
                <div class="contact-box">
                    <h3><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg> Hours</h3>
                    <p>Mon-Fri: 8AM-5PM<br>Sat: 8AM-12PM</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Contact Form -->
    <div class="card">
        <div class="card-header">
            <h2>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                Send Us a Message
            </h2>
        </div>
        <div class="card-body">
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                
                <div class="form-group">
                    <label class="form-label">Your Name</label>
                    <input type="text" class="form-control" name="name" 
                           value="<?php echo htmlspecialchars($member_name ?: $current_user['full_name']); ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Email Address</label>
                    <input type="email" class="form-control" name="email" 
                           value="<?php echo htmlspecialchars($member_email ?: $current_user['email']); ?>" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Subject</label>
                    <input type="text" class="form-control" name="subject" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Category</label>
                    <select class="form-select" name="category" required>
                        <option value="">Select Category</option>
                        <option value="Payment Issue">Payment Issue</option>
                        <option value="Account Problem">Account Problem</option>
                        <option value="Beneficiary">Beneficiary Concern</option>
                        <option value="Death Benefits">Death Benefits</option>
                        <option value="Damayan">Damayan / Financial Assistance</option>
                        <option value="Membership">Membership Inquiry</option>
                        <option value="Technical">Technical Issue</option>
                        <option value="General">General Inquiry</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Message</label>
                    <textarea class="form-control" name="message" rows="4" required></textarea>
                </div>
                
                <button type="submit" name="send_message" class="btn-submit">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                    Send Message
                </button>
            </form>
        </div>
    </div>

    <!-- Message History -->
    <?php if (!empty($user_messages)): ?>
    <div class="card">
        <div class="card-header">
            <h2>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                Your Message History
            </h2>
        </div>
        <div class="card-body">
            <?php foreach ($user_messages as $msg): ?>
            <div class="message-item">
                <div class="message-header">
                    <div class="message-subject"><?php echo htmlspecialchars($msg['subject']); ?></div>
                    <span class="message-status status-<?php echo $msg['status']; ?>">
                        <?php echo ucfirst($msg['status']); ?>
                    </span>
                </div>
                <div class="message-meta">
                    <span><?php echo htmlspecialchars($msg['category']); ?></span>
                    <span>·</span>
                    <span><?php echo date('M d, Y', strtotime($msg['created_at'])); ?></span>
                </div>
                <div class="message-preview">
                    <?php echo htmlspecialchars(substr($msg['message'], 0, 100)); ?>...
                </div>
                <?php if (!empty($msg['admin_reply'])): ?>
                <div class="message-reply">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 17 4 12 9 7"/><path d="M20 18v-2a4 4 0 0 0-4-4H4"/></svg>
                    <span><strong>Admin:</strong> <?php echo htmlspecialchars(substr($msg['admin_reply'], 0, 80)); ?>...</span>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- FAQ -->
    <div class="card">
        <div class="card-header">
            <h2>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                Frequently Asked Questions
            </h2>
        </div>
        <div class="card-body">
            <?php foreach ($faqs_by_category as $category => $category_faqs): ?>
                <div class="category-tag"><?php echo htmlspecialchars($category); ?></div>
                <?php foreach ($category_faqs as $faq): ?>
                <div class="faq-item">
                    <div class="faq-question" onclick="toggleFAQ(this)">
                        <span><?php echo htmlspecialchars($faq['question']); ?></span>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
                    </div>
                    <div class="faq-answer">
                        <?php echo nl2br(htmlspecialchars($faq['answer'])); ?>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Support Services -->
    <div class="card">
        <div class="card-header">
            <h2>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
                Support Services Available
            </h2>
        </div>
        <div class="card-body">
            <div class="services-grid">
                <div class="service-item">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                    <h3>Damayan</h3>
                    <small>Emergency financial assistance</small>
                </div>
                <div class="service-item">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
                    <h3>Death Benefits</h3>
                    <small>Burial & death assistance</small>
                </div>
                <div class="service-item">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c3 3 9 3 12 0v-5"/></svg>
                    <h3>Educational</h3>
                    <small>Scholarships & assistance</small>
                </div>
                <div class="service-item">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="20" x2="12" y2="10"/><line x1="18" y1="20" x2="18" y2="4"/><line x1="6" y1="20" x2="6" y2="16"/></svg>
                    <h3>Livelihood</h3>
                    <small>Business & livelihood loans</small>
                </div>
            </div>
        </div>
    </div>

    <!-- Map -->
    <div class="card">
        <div class="card-header">
            <h2>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                Our Location
            </h2>
        </div>
        <div class="map-wrapper">
            <iframe 
                src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d15432.567890123456!2d121.083333!3d15.416667!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x3390c5e2b3c5a2a1%3A0x9b8c5a2a1b3c5a2a!2sPalayan%20City%2C%20Nueva%20Ecija!5e0!3m2!1sen!2sph!4v1700000000000!5m2!1sen!2sph" 
                allowfullscreen="" 
                loading="lazy"
                referrerpolicy="no-referrer-when-downgrade">
            </iframe>
        </div>
    </div>

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
    <a href="profile.php" class="nav-item">
        <span class="icon">👤</span>
        <span>Profile</span>
    </a>
    <a href="support.php" class="nav-item active">
        <span class="icon">❓</span>
        <span>Help</span>
    </a>
</nav>

<script>
// FAQ Toggle
function toggleFAQ(element) {
    const answer = element.nextElementSibling;
    const parent = element.parentElement;
    answer.classList.toggle('show');
    parent.classList.toggle('open');
}

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