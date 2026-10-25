<?php
// user/dashboard.php — senior-friendly dashboard (2026 redesign)
require_once '../includes/config.php';
require_once '../includes/db_connection.php';
require_once '../includes/security.php';
require_once '../includes/auth.php';
require_once '../includes/user_functions.php';
require_once '../includes/dashboard_data.php';

$auth->requireLogin();
// Fast-path: if not approved, go to welcome
if (($_SESSION['role'] ?? '') !== 'admin'
    && ($_SESSION['account_status'] ?? 'joined') !== 'approved') {
    header('Location: welcome.php');
    exit();
}
$current_user = $auth->getCurrentUser();
$db = Database::getInstance();

// ---------- Find the member ----------
$member = getUserMemberData($db, $current_user);

if (!$member && !empty($current_user['user_id'])) {
    $member = $db->getSingle("SELECT * FROM members WHERE user_id = ?", [$current_user['user_id']], 'i');
}
if (!$member && !empty($current_user['username'])) {
    $member = $db->getSingle("SELECT * FROM members WHERE username = ?", [$current_user['username']], 's');
}
if (!$member || empty($member['member_code'])) {
    // Joined / pending / rejected users don't have a member row yet
    header('Location: welcome.php');
    exit();
}

// ---------- Deceased / inactive → status page ----------
$member_status = $member['status'] ?? 'active';
if ($member_status !== 'active') {
    header('Location: account_status.php?reason=' . urlencode($member_status));
    exit();
}

$member_code = $member['member_code'];

// ---------- Compute everything ----------
try {
    $d = getMemberDashboard($db, $member_code);

    // 1. Get ALL confirmed payments (Strict member_code)
    $paid_res = $db->getSingle("SELECT SUM(amount) as total FROM payments WHERE member_code = ? AND payment_status = 'confirmed'", [$member_code], 's');
    $total_paid_amt = (float)($paid_res['total'] ?? 0);
    
    $monthly_amt = (float)($member['monthly_contribution'] ?? 100);
    if ($monthly_amt <= 0) $monthly_amt = 100;

    // 2. Get actual distinct paid months
    $paid_months_res = $db->getSingle("SELECT COUNT(DISTINCT COALESCE(period_month, DATE_FORMAT(payment_date, '%Y-%m'))) as months FROM payments WHERE member_code = ? AND payment_status = 'confirmed'", [$member_code], 's');
    $months_paid_actual = (int)($paid_months_res['months'] ?? 0);

    // 3. Calculate expected months from date_joined to current month
    $joined_dt = new DateTime($member['date_joined']);
    $joined_dt->modify('first day of this month');
    
    $now_dt = new DateTime();
    $now_dt->modify('first day of this month');

    $months_expected_now = 0;
    $cursor = clone $joined_dt;
    while ($cursor <= $now_dt) {
        $months_expected_now++;
        $cursor->modify('+1 month');
    }
    $months_expected_now = max(1, $months_expected_now);

    $expected_amount_now = $months_expected_now * $monthly_amt;
    
    // Set Next Due Date to the 15th of NEXT month
    $next_due_dt = clone $now_dt;
    $next_due_dt->modify('+1 month');
    $next_due_dt->setDate($next_due_dt->format('Y'), $next_due_dt->format('m'), 15);

    if ($total_paid_amt >= $expected_amount_now) {
        $advance_amount = $total_paid_amt - $expected_amount_now;
        $advance_months = floor($advance_amount / $monthly_amt);
        
        $d['advance_months'] = $advance_months;
        $d['balance'] = 0;
        $d['is_overdue'] = false;
        
        if ($advance_months > 0) {
            $advance_until_dt = clone $now_dt;
            $advance_until_dt->modify('+' . $advance_months . ' months');
            $d['advance_until'] = $advance_until_dt->format('Y-m');
            
            $next_due_dt = clone $advance_until_dt;
            $next_due_dt->modify('+1 month');
            $next_due_dt->setDate($next_due_dt->format('Y'), $next_due_dt->format('m'), 15);
        } else {
            $d['advance_until'] = null;
        }
        
        $d['total_paid'] = $total_paid_amt;
        $d['months_paid'] = $months_paid_actual;
        $d['months_expected'] = max($months_expected_now, $months_paid_actual);
        $d['months_missing'] = 0;
        
    } else {
        $deficit = $expected_amount_now - $total_paid_amt;
        $d['advance_months'] = 0;
        $d['balance'] = $deficit;
        
        $missed_months = $months_expected_now - $months_paid_actual;
        $d['is_overdue'] = ($missed_months >= 2);
        
        $d['months_paid'] = $months_paid_actual;
        $d['months_expected'] = $months_expected_now;
        $d['months_missing'] = max(0, $missed_months);
        $d['total_paid'] = $total_paid_amt;
    }

    $d['next_due_date'] = $next_due_dt->format('Y-m-d');
    $d['next_due_period'] = $next_due_dt->format('Y-m');

    if (!empty($d['error'])) {
        throw new RuntimeException('dashboard_data_failed');
    }
} catch (Throwable $e) {
    $d = [
        'member' => $member, 'monthly' => 100, 'months_paid' => 0, 'months_expected' => 0,
        'months_missing' => 0, 'total_paid' => 0, 'balance' => 0, 'advance_months' => 0,
        'advance_until' => null, 'next_due_period' => null, 'next_due_date' => null,
        'is_overdue' => false, 'benefit' => ['total_benefit' => 0, 'years_as_member' => 0],
        '_error' => true,
    ];
}

// ---------- Prepare display values ----------
$total_paid     = (float)$d['total_paid'];
$balance        = (float)$d['balance'];
$advance_months = (int)$d['advance_months'];
$advance_until  = $d['advance_until'];
$next_due_date  = $d['next_due_date'];
$is_overdue     = (bool)$d['is_overdue'];
$months_missing = (int)$d['months_missing'];

$benefit        = $d['benefit'];
$total_benefit  = (float)($benefit['total_benefit'] ?? 0);
$years_member   = (int)($benefit['years_as_member'] ?? 0);
$is_good_payer  = (bool)($benefit['is_good_payer'] ?? false);
$pending        = $d['pending_payments'][0] ?? null;

$next_due_display = $next_due_date ? date('F j, Y', strtotime($next_due_date)) : '—';
$monthly_display = number_format((float)$d['monthly'], 2);

$progress_pct = $d['months_expected'] > 0 ? min(100, round(($d['months_paid'] / $d['months_expected']) * 100)) : 0;
$is_admin = ($current_user['role'] === 'admin');
$csrf_token = Security::generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=2.0, user-scalable=yes">
<title>Dashboard — <?php echo defined('APP_NAME') ? APP_NAME : 'NHGL'; ?></title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
:root {
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
body{background:var(--paper);font-family:'Inter',sans-serif;font-size:1rem;line-height:1.6;color:var(--ink-900);margin:0;padding:0;min-height:100vh;-webkit-font-smoothing:antialiased}

/* Header */
.app-header{background:var(--surface);border-bottom:1px solid var(--line);padding:0.85rem 1rem;display:flex;align-items:center;justify-content:space-between;gap:12px;position:sticky;top:0;z-index:50;box-shadow:var(--shadow-sm);}
.app-brand{display:flex;align-items:center;gap:10px;min-width:0}
.app-brand .logo-wrap{width:44px;height:44px;border-radius:10px;background:var(--brand-900);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:900;font-size:1rem;flex-shrink:0;overflow:hidden;}
.app-brand .logo-wrap img{width:100%;height:100%;object-fit:contain;background:#fff;padding:4px;}
.app-brand .brand-text{display:flex;flex-direction:column;line-height:1.15;min-width:0}
.app-brand .brand-name{font-weight:800;font-size:1rem;color:var(--ink-900)}
.header-actions{display:flex;gap:8px;flex-shrink:0;align-items:center}
.icon-btn{width:44px;height:44px;border-radius:10px;background:var(--paper);border:1px solid var(--line);color:var(--ink-700);display:inline-flex;align-items:center;justify-content:center;font-size:1.1rem;cursor:pointer;transition:all 0.15s;text-decoration:none;font-family:inherit;}
.icon-btn:hover{background:var(--brand-50);border-color:var(--brand-500);color:var(--brand-700)}

.main{max-width:720px;margin:0 auto;padding:1.25rem 1rem 6rem}

/* Hero */
.hero{border-radius:var(--radius-lg);padding:1.5rem 1.4rem;margin-bottom:1rem;position:relative;overflow:hidden;box-shadow:var(--shadow-lg)}
.hero.hero-pay{background:linear-gradient(135deg,var(--brand-800) 0%,var(--brand-600) 100%);color:#fff}
.hero.hero-paid{background:linear-gradient(135deg,#14532d 0%,#15803d 100%);color:#fff}
.hero.hero-advance{background:linear-gradient(135deg,#b45309 0%,#d97706 100%);color:#fff}
.hero.hero-overdue{background:linear-gradient(135deg,#7f1d1d 0%,#991b1b 100%);color:#fff}
.hero::before{content:'';position:absolute;top:-80px;right:-80px;width:260px;height:260px;background:radial-gradient(circle,rgba(255,255,255,0.14) 0%,transparent 70%);border-radius:50%;pointer-events:none}
.hero-content{position:relative;z-index:2}
.hero-label{font-size:0.82rem;text-transform:uppercase;letter-spacing:0.1em;font-weight:800;opacity:0.85;margin-bottom:0.4rem;display:flex;align-items:center;gap:8px}
.hero-amount{font-size:2.6rem;font-weight:900;line-height:1;letter-spacing:-0.03em;margin-bottom:0.4rem}
.hero-amount .cur{font-size:1.6rem;font-weight:700;opacity:0.85;margin-right:4px;vertical-align:8px}
.hero-detail{font-size:1rem;font-weight:500;opacity:0.95;margin-bottom:1.1rem;line-height:1.5}
.hero-detail strong{font-weight:800}
.hero-actions{display:flex;flex-direction:column;gap:10px}
.btn-primary{background:var(--yellow-accent);color:var(--brand-900);border:none;padding:1rem 1.5rem;border-radius:var(--radius);font-size:1.05rem;font-weight:800;font-family:inherit;cursor:pointer;transition:all 0.18s;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;gap:10px;box-shadow:0 4px 14px rgba(251,191,36,0.35);letter-spacing:0.01em;min-height:56px}
.btn-primary:hover{transform:translateY(-2px);box-shadow:0 8px 20px rgba(251,191,36,0.5)}
.btn-secondary-light{background:rgba(255,255,255,0.15);color:#fff;border:1.5px solid rgba(255,255,255,0.35);padding:1rem 1.5rem;border-radius:var(--radius);font-size:1rem;font-weight:700;font-family:inherit;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;gap:10px;transition:all 0.18s;min-height:56px}
.btn-secondary-light:hover{background:rgba(255,255,255,0.25);border-color:rgba(255,255,255,0.6)}

/* Sections & Cards */
.section{margin-bottom:1rem}
.section-title{font-size:0.78rem;text-transform:uppercase;letter-spacing:0.1em;font-weight:800;color:var(--ink-500);margin:0 0 0.6rem;padding:0 4px;display:flex;align-items:center;gap:8px}
.section-title::before{content:'';display:inline-block;width:4px;height:14px;background:var(--yellow-accent);border-radius:2px}

.stats-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:1rem}
.stat-card{background:var(--surface);border:1px solid var(--line);border-radius:var(--radius);padding:1rem;box-shadow:var(--shadow-sm);display:flex;flex-direction:column;gap:4px;min-height:100px;justify-content:center}
.stat-card .stat-label{font-size:0.75rem;text-transform:uppercase;letter-spacing:0.05em;font-weight:700;color:var(--ink-500);display:flex;align-items:center;gap:6px}
.stat-card .stat-value{font-size:1.5rem;font-weight:900;color:var(--ink-900);line-height:1.1;letter-spacing:-0.02em;display:flex;align-items:baseline;gap:4px;flex-wrap:wrap}
.stat-card .stat-value small{font-size:0.85rem;font-weight:600;color:var(--ink-500);letter-spacing:0}
.stat-card .stat-hint{font-size:0.78rem;color:var(--ink-500);font-weight:500;margin-top:2px;line-height:1.3}
.stat-progress{height:8px;background:var(--line-soft);border-radius:100px;overflow:hidden;margin-top:8px}
.stat-progress .fill{height:100%;background:linear-gradient(90deg,var(--success-500),#22c55e);border-radius:100px;transition:width 0.6s ease}

/* Pending */
.pending-card{background:var(--warning-100);border:2px solid #fcd34d;border-radius:var(--radius);padding:1rem 1.25rem;margin-bottom:1rem;display:flex;gap:14px;align-items:flex-start}
.pending-card .icon{width:44px;height:44px;border-radius:50%;background:#fff;color:var(--warning-700);display:flex;align-items:center;justify-content:center;font-size:1.2rem;flex-shrink:0;font-weight:900}
.pending-card .body{flex:1;min-width:0}
.pending-card .title{font-weight:800;color:var(--warning-700);font-size:1rem;margin-bottom:4px}
.pending-card .desc{color:#78350f;font-size:0.9rem;line-height:1.5}
.pending-card .ref{display:inline-block;margin-top:6px;font-family:'Courier New',monospace;font-size:0.85rem;background:#fff;padding:3px 10px;border-radius:6px;font-weight:700;color:var(--warning-700)}

/* Benefit */
.benefit-card{background:var(--surface);border:1px solid var(--line);border-radius:var(--radius-lg);overflow:hidden;box-shadow:var(--shadow-sm)}
.benefit-head{background:linear-gradient(135deg,#1e2f45 0%,#2c4a6b 100%);color:#fff;padding:1.25rem 1.4rem;position:relative;overflow:hidden}
.benefit-head::before{content:'';position:absolute;top:-60px;right:-60px;width:180px;height:180px;background:radial-gradient(circle at top right,rgba(251,191,36,0.22) 0%,transparent 65%);pointer-events:none}
.benefit-head .ttl{font-size:0.75rem;text-transform:uppercase;letter-spacing:0.1em;font-weight:800;color:rgba(255,255,255,0.8);margin-bottom:6px;position:relative}
.benefit-head .amt{font-size:2.1rem;font-weight:900;color:var(--yellow-accent);line-height:1;letter-spacing:-0.02em;position:relative}
.benefit-head .amt .cur{font-size:1.35rem;opacity:0.9;margin-right:3px;vertical-align:6px}
.benefit-head .sub{font-size:0.9rem;color:rgba(255,255,255,0.82);margin-top:8px;line-height:1.5;position:relative}
.benefit-head .sub strong{color:#fff;font-weight:700}
.benefit-toggle{width:100%;background:var(--surface);border:none;border-top:1px solid var(--line-soft);padding:1rem 1.4rem;font-size:0.92rem;font-weight:700;font-family:inherit;color:var(--brand-600);cursor:pointer;display:flex;align-items:center;justify-content:space-between;gap:10px;min-height:52px;transition:background 0.15s}
.benefit-toggle:hover{background:var(--brand-50)}
.benefit-toggle .chev{transition:transform 0.2s;font-size:0.9rem;color:var(--ink-400)}
.benefit-toggle.open .chev{transform:rotate(180deg)}
.benefit-body{display:none;padding:0.5rem 1.4rem 1.25rem;background:var(--brand-50);border-top:1px solid var(--line-soft)}
.benefit-body.open{display:block}
.benefit-row{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:0.7rem 0;font-size:0.95rem;border-bottom:1px dashed var(--line)}
.benefit-row:last-child{border-bottom:none}
.benefit-row .label{display:flex;flex-direction:column;min-width:0;line-height:1.3}
.benefit-row .label .main{font-weight:600;color:var(--ink-900)}
.benefit-row .label .sub{font-size:0.78rem;color:var(--ink-500);font-weight:500;margin-top:2px}
.benefit-row .amt{font-weight:800;font-variant-numeric:tabular-nums;white-space:nowrap;color:var(--ink-900)}
.benefit-row .amt.pos{color:var(--success-700)}
.benefit-row .amt.neg{color:var(--danger-700)}
.benefit-row .amt.dim{color:var(--ink-400)}
.benefit-total{border-top:2px solid var(--brand-500);padding-top:0.9rem;margin-top:0.4rem;display:flex;align-items:center;justify-content:space-between;gap:10px}
.benefit-total .lbl{font-size:0.82rem;text-transform:uppercase;letter-spacing:0.08em;font-weight:800;color:var(--ink-700)}
.benefit-total .val{font-size:1.35rem;font-weight:900;color:var(--brand-700);letter-spacing:-0.02em}
.benefit-note{font-size:0.82rem;color:var(--ink-500);margin-top:0.7rem;line-height:1.5;padding:0.7rem 0.9rem;background:#fff;border-radius:var(--radius-sm);border:1px solid var(--line)}

/* Payments list */
.payments-list{background:var(--surface);border:1px solid var(--line);border-radius:var(--radius-lg);overflow:hidden;box-shadow:var(--shadow-sm)}
.payment-item{display:flex;align-items:center;gap:14px;padding:1rem 1.25rem;border-bottom:1px solid var(--line-soft);text-decoration:none;color:inherit;transition:background 0.15s}
.payment-item:last-child{border-bottom:none}
.payment-item:hover{background:var(--brand-50)}
.payment-icon{width:44px;height:44px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;flex-shrink:0;font-weight:900}
.payment-icon.cash{background:var(--success-100);color:var(--success-700)}
.payment-icon.gcash{background:#dbeafe;color:#1e40af}
.payment-icon.bank{background:var(--yellow-100);color:var(--yellow-dark)}
.payment-body{flex:1;min-width:0}
.payment-title{font-weight:700;color:var(--ink-900);font-size:0.95rem;line-height:1.3;margin-bottom:2px}
.payment-meta{font-size:0.82rem;color:var(--ink-500);font-weight:500;display:flex;flex-wrap:wrap;gap:8px;align-items:center}
.payment-amt{font-weight:900;color:var(--ink-900);font-size:1.05rem;font-variant-numeric:tabular-nums;white-space:nowrap;flex-shrink:0}
.payment-list-footer{padding:0.9rem 1.25rem;background:var(--paper);border-top:1px solid var(--line-soft);text-align:center}
.btn-link{display:inline-flex;align-items:center;gap:6px;color:var(--brand-600);font-weight:700;font-size:0.92rem;text-decoration:none;padding:8px 12px;border-radius:var(--radius-sm);transition:background 0.15s;font-family:inherit;background:transparent;border:none;cursor:pointer;min-height:44px}
.btn-link:hover{background:var(--brand-100)}

/* Drawer */
.drawer-overlay { position: fixed; inset: 0; background: rgba(15,41,66,0.5); z-index: 1040; opacity: 0; visibility: hidden; transition: all 0.3s ease; backdrop-filter: blur(2px); }
.drawer-overlay.open { opacity: 1; visibility: visible; }
.drawer { position: fixed; top: 0; right: -300px; width: 280px; height: 100vh; background: var(--surface); z-index: 1050; box-shadow: -5px 0 25px rgba(0,0,0,0.15); transition: right 0.3s ease; display: flex; flex-direction: column; }
.drawer.open { right: 0; }
.drawer-header { padding: 1.25rem 1rem; border-bottom: 1px solid var(--line); display: flex; align-items: center; justify-content: space-between; background: var(--brand-50); }
.drawer-header h3 { margin: 0; font-size: 1rem; font-weight: 800; color: var(--ink-900); }
.drawer-close { background: transparent; border: none; font-size: 1.25rem; color: var(--ink-500); cursor: pointer; padding: 4px; }
.drawer-body { padding: 1rem; flex: 1; overflow-y: auto; display: flex; flex-direction: column; gap: 4px; }
.drawer-item { display: flex; align-items: center; gap: 12px; padding: 12px 16px; text-decoration: none; color: var(--ink-700); font-weight: 600; font-size: 0.95rem; border-radius: var(--radius-sm); transition: all 0.15s; }
.drawer-item:hover { background: var(--brand-50); color: var(--brand-700); }
.drawer-item svg { width: 20px; height: 20px; color: var(--brand-600); }
.drawer-footer { padding: 1rem; border-top: 1px solid var(--line); }
.btn-logout { display: flex; align-items: center; justify-content: center; gap: 8px; width: 100%; padding: 12px; background: var(--danger-100); color: var(--danger-700); border: none; border-radius: var(--radius-sm); font-weight: 800; font-size: 0.95rem; cursor: pointer; font-family: inherit; text-decoration: none; }

/* Bottom Nav */
.bottom-nav{position:fixed;bottom:0;left:0;right:0;background:var(--surface);border-top:1px solid var(--line);display:flex;z-index:60;box-shadow:0 -4px 14px rgba(16,24,40,0.06);}
.bottom-nav .nav-item{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:3px;padding:10px 4px 12px;text-decoration:none;color:var(--ink-500);font-size:0.68rem;font-weight:600;transition:all 0.2s;min-height:64px;position:relative;}
.bottom-nav .nav-item:hover{color:var(--brand-600);background:var(--brand-50)}
.bottom-nav .nav-item.active{color:var(--brand-700);background:var(--brand-50)}
.bottom-nav .nav-item.active::before{content:'';position:absolute;top:0;left:12%;right:12%;height:3px;background:var(--yellow-accent);border-radius:0 0 3px 3px;animation:fadeIn 0.3s ease-in-out;}
.bottom-nav .nav-item .icon{font-size:1.35rem;line-height:1;font-weight:900}
.bottom-nav .nav-item span:last-child{margin-top:2px}

@keyframes fadeIn { from { opacity: 0; transform: scaleX(0.5); } to { opacity: 1; transform: scaleX(1); } }

.error-banner{background:var(--warning-100);border:2px solid #fcd34d;color:var(--warning-700);padding:1rem 1.25rem;border-radius:var(--radius);margin-bottom:1rem;font-weight:600;line-height:1.5}

@media (min-width:700px){
    .stats-grid{grid-template-columns:repeat(4,1fr)}
    .hero-actions{flex-direction:row}
    .hero-actions > *{flex:1}
    .main{padding-bottom:2rem}
    .bottom-nav{position:static;max-width:720px;margin:0 auto 2rem;border-radius:var(--radius);border:1px solid var(--line);box-shadow:var(--shadow-sm)}
    .bottom-nav .nav-item{font-size:0.75rem}
}
@media (max-width:540px){
    html{font-size:17px}
    .main{padding:0.85rem 0.75rem 6rem}
    .hero-amount{font-size:2.2rem}
    .benefit-head .amt{font-size:1.8rem}
    .stat-card .stat-value{font-size:1.3rem}
    .app-brand .brand-sub{display:none}
    .bottom-nav .nav-item{font-size:0.65rem}
    .bottom-nav .nav-item .icon{font-size:1.25rem}
}
</style>
</head>
<body>

<div class="drawer-overlay" id="drawerOverlay"></div>

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

<header class="app-header">
    <div class="app-brand">
        <div class="logo-wrap">
            <img src="../assets/images/harana-logo.png" alt="NHGL" onerror="this.style.display='none';this.parentNode.textContent='NH';">
        </div>
        <div class="brand-text">
            <span class="brand-name">NHGL</span>
        </div>
    </div>
    <div class="header-actions">
        <button class="icon-btn" id="openDrawer" aria-label="Menu">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="1"/><circle cx="12" cy="5" r="1"/><circle cx="12" cy="19" r="1"/></svg>
        </button>
    </div>
</header>

<main class="main">

<?php if (!empty($d['_error'])): ?>
    <div class="error-banner">
        We couldn't load your live balance right now. Please refresh the page in a moment.
        If the problem continues, contact the office.
    </div>
<?php endif; ?>

<?php if ($pending): ?>
    <div class="pending-card">
        <div class="icon">…</div>
        <div class="body">
            <div class="title">A GCash payment is waiting for confirmation</div>
            <div class="desc">
                We received your GCash payment of
                <strong>₱<?php echo number_format((float)$pending['amount'], 2); ?></strong>
                on <?php echo date('M j, Y', strtotime($pending['payment_date'])); ?>.
                The office will confirm it within 1–2 business days.
            </div>
            <?php if (!empty($pending['gcash_reference'])): ?>
                <span class="ref">Ref: <?php echo htmlspecialchars($pending['gcash_reference']); ?></span>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php if ($is_overdue): ?>
    <div class="hero hero-overdue">
        <div class="hero-content">
            <div class="hero-label">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                Payment overdue
            </div>
            <div class="hero-amount"><span class="cur">₱</span><?php echo number_format(abs($balance), 2); ?></div>
            <div class="hero-detail">
                You have <strong><?php echo $months_missing; ?> unpaid month<?php echo $months_missing === 1 ? '' : 's'; ?></strong>.
                Please visit the office to settle this. Late payments require personal appearance.
            </div>
            <div class="hero-actions">
                <a href="support.php" class="btn-secondary-light">Call the office</a>
            </div>
        </div>
    </div>
<?php elseif ($advance_months > 0): ?>
    <div class="hero hero-advance">
        <div class="hero-content">
            <div class="hero-label">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                You're paid ahead
            </div>
            <div class="hero-amount">₱<?php echo number_format($total_paid, 2); ?></div>
            <div class="hero-detail">
                Credit toward future months. You're covered through
                <strong><?php echo $advance_until ? date('F Y', strtotime($advance_until . '-01')) : ''; ?></strong>.
                <?php if ($next_due_date): ?>
                    Next payment won't be due until <strong><?php echo $next_due_display; ?></strong>.
                <?php endif; ?>
            </div>
            <div class="hero-actions">
                <a href="advance_payment.php" class="btn-primary" style="background:#fff; color:#b45309;">View Advance Wallet</a>
                <a href="payments.php" class="btn-secondary-light">Payment history</a>
            </div>
        </div>
    </div>
<?php elseif ($balance <= 0): ?>
    <div class="hero hero-paid">
        <div class="hero-content">
            <div class="hero-label">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                You're all paid up
            </div>
            <div class="hero-amount">₱0.00</div>
            <div class="hero-detail">
                Your next payment of <strong>₱<?php echo $monthly_display; ?></strong>
                <?php if ($next_due_date): ?>
                    is due <strong><?php echo $next_due_display; ?></strong>.
                <?php endif; ?>
            </div>
            <div class="hero-actions">
                <a href="advance_payment.php" class="btn-secondary-light">Pay <?php echo $monthly_display; ?> in advance</a>
                <a href="payments.php" class="btn-secondary-light">View payment history</a>
            </div>
        </div>
    </div>
<?php else: ?>
    <div class="hero hero-pay">
        <div class="hero-content">
            <div class="hero-label">Next payment</div>
            <div class="hero-amount"><span class="cur">₱</span><?php echo number_format($balance, 2); ?></div>
            <div class="hero-detail">
                <?php if ($next_due_date): ?>
                    Due on <strong><?php echo $next_due_display; ?></strong>.
                <?php endif; ?>
                Monthly contribution: <strong>₱<?php echo $monthly_display; ?></strong>.
            </div>
            <div class="hero-actions">
                <a href="payments.php" class="btn-primary">Pay ₱<?php echo $monthly_display; ?> now</a>
                <a href="payments.php" class="btn-secondary-light">View payment history</a>
            </div>
        </div>
    </div>
<?php endif; ?>

<section class="section">
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-label">Total paid</div>
            <div class="stat-value">₱<?php echo number_format($total_paid, 0); ?></div>
            <div class="stat-hint"><?php echo $d['months_paid']; ?> month<?php echo $d['months_paid'] === 1 ? '' : 's'; ?> × ₱<?php echo number_format($d['monthly'], 0); ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Months paid</div>
            <div class="stat-value"><?php echo $d['months_paid']; ?><small>of <?php echo $d['months_expected']; ?></small></div>
            <div class="stat-progress"><div class="fill" style="width: <?php echo $progress_pct; ?>%;<?php if ($progress_pct < 60) echo ' background: linear-gradient(90deg, var(--danger-500), #ef4444);'; ?>"></div></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Next due</div>
            <div class="stat-value" style="font-size: 1.15rem;<?php echo $is_overdue ? ' color: var(--danger-700);' : ''; ?>">
                <?php if ($is_overdue) {
                    echo 'Overdue';
                } elseif ($next_due_date) {
                    echo date('M j', strtotime($next_due_date));
                } else {
                    echo '—';
                } ?>
            </div>
            <div class="stat-hint">
                <?php if ($is_overdue) {
                    echo 'Please settle at the office';
                } elseif ($next_due_date) {
                    echo date('Y', strtotime($next_due_date));
                } else {
                    echo 'No pending payment';
                } ?>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-label"><?php echo $months_missing > 0 ? 'Missing' : 'Status'; ?></div>
            <div class="stat-value" style="color: <?php echo $months_missing > 0 ? 'var(--danger-700)' : 'var(--success-700)'; ?>;">
                <?php echo $months_missing > 0 ? $months_missing : '✓'; ?>
            </div>
            <div class="stat-hint">
                <?php if ($months_missing > 0) {
                    echo 'month' . ($months_missing === 1 ? '' : 's') . ' unpaid';
                } else {
                    echo 'On-time payer';
                } ?>
            </div>
        </div>
    </div>
</section>

<section class="section">
    <h2 class="section-title">Your benefit</h2>
    <div class="benefit-card">
        <div class="benefit-head">
            <div class="ttl">Estimated payout to your family</div>
            <div class="amt"><span class="cur">₱</span><?php echo number_format($total_benefit, 0); ?></div>
            <div class="sub">
                <?php if (!empty($benefit['error'])): ?>
                    Benefit calculation is temporarily unavailable. Please contact the office.
                <?php elseif ($years_member >= 1): ?>
                    Based on <strong><?php echo $years_member; ?> year<?php echo $years_member === 1 ? '' : 's'; ?></strong> of membership.
                <?php else: ?>
                    Based on <strong>under 1 year</strong> of membership.
                <?php endif; ?>
            </div>
        </div>
        <button class="benefit-toggle" type="button" onclick="toggleBenefit(this)">
            <span>See how this is calculated</span>
            <svg class="chev" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
        </button>
        <div class="benefit-body">
            <?php if (!empty($benefit['error'])): ?>
                <div class="benefit-row">
                    <span class="label"><span class="main">We can't load the breakdown right now</span><span class="sub">Please try again later or contact the office.</span></span>
                    <span class="amt dim">—</span>
                </div>
            <?php else: ?>
                <div class="benefit-row">
                    <span class="label">
                        <span class="main">Base benefit</span>
                        <span class="sub"><?php echo $years_member; ?> year bracket</span>
                    </span>
                    <span class="amt">₱<?php echo number_format((float)($benefit['base_benefit'] ?? 0), 2); ?></span>
                </div>
                <div class="benefit-row">
                    <span class="label">
                        <span class="main">Good payer bonus</span>
                        <span class="sub">Requires 5+ years, no missed payments</span>
                    </span>
                    <span class="amt <?php echo $is_good_payer ? 'pos' : 'dim'; ?>">
                        <?php echo $is_good_payer
                            ? '+ ₱' . number_format((float)$benefit['good_payer_bonus'], 2)
                            : '—'; ?>
                    </span>
                </div>
                <?php if (($benefit['deduction'] ?? 0) > 0): ?>
                    <div class="benefit-row">
                        <span class="label">
                            <span class="main">Missed payment deduction</span>
                            <span class="sub"><?php echo htmlspecialchars($benefit['deduction_reason'] ?: $months_missing . ' missed payments'); ?></span>
                        </span>
                        <span class="amt neg">−₱<?php echo number_format((float)$benefit['deduction'], 2); ?></span>
                    </div>
                <?php else: ?>
                    <div class="benefit-row">
                        <span class="label">
                            <span class="main">Missed payment deduction</span>
                            <span class="sub">No missed payments</span>
                        </span>
                        <span class="amt pos">₱0</span>
                    </div>
                <?php endif; ?>
                <div class="benefit-total">
                    <span class="lbl">Total benefit</span>
                    <span class="val">₱<?php echo number_format($total_benefit, 2); ?></span>
                </div>
                <div class="benefit-note">
                    <strong>+ ₱<?php echo number_format((float)($benefit['accident_bonus'] ?? 10000), 2); ?></strong>
                    if death is caused by an accident.<br>
                    Maximum possible: <strong>₱<?php echo number_format($total_benefit + (float)($benefit['accident_bonus'] ?? 10000), 2); ?></strong>.
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>

<?php if (!empty($d['recent_payments'])): ?>
<section class="section">
    <h2 class="section-title">Recent payments</h2>
    <div class="payments-list">
        <?php foreach ($d['recent_payments'] as $p):
            $method = $p['payment_method'] ?? 'cash';
            $icon_class = $method === 'gcash' ? 'gcash' : ($method === 'bank_transfer' ? 'bank' : 'cash');
            $period_label = !empty($p['period'])
                ? date('F Y', strtotime($p['period'] . '-01'))
                : date('F Y', strtotime($p['payment_date']));
        ?>
        <a href="payments.php?receipt=<?php echo urlencode($p['receipt_number'] ?? ''); ?>" class="payment-item">
            <div class="payment-icon <?php echo $icon_class; ?>">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
            </div>
            <div class="payment-body">
                <div class="payment-title"><?php echo htmlspecialchars($period_label); ?></div>
                <div class="payment-meta">
                    <span><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $method))); ?></span>
                    <span>·</span>
                    <span><?php echo date('M j, Y', strtotime($p['payment_date'])); ?></span>
                </div>
            </div>
            <div class="payment-amt">₱<?php echo number_format((float)$p['amount'], 0); ?></div>
        </a>
        <?php endforeach; ?>
        <div class="payment-list-footer">
            <a href="payments.php" class="btn-link">See all payments →</a>
        </div>
    </div>
</section>
<?php endif; ?>

</main>

<nav class="bottom-nav">
    <a href="dashboard.php" class="nav-item active">
        <span class="icon">🏠</span>
        <span>Home</span>
    </a>
    <a href="notifications.php" class="nav-item">
        <span class="icon">🔔</span>
        <span>Notify</span>
    </a>
    <a href="payments.php" class="nav-item">
        <span class="icon">💳</span>
        <span>Pay</span>
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
function toggleBenefit(btn) {
    btn.classList.toggle('open');
    const body = btn.nextElementSibling;
    if (body) body.classList.toggle('open');
}

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