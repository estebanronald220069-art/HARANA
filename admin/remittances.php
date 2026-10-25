<?php
// admin/remittances.php
require_once '../includes/config.php';
require_once '../includes/db_connection.php';
require_once '../includes/security.php';
require_once '../includes/auth.php';

$auth->requireLogin();
$current_user = $auth->getCurrentUser();
if ($current_user['role'] !== 'admin') {
    header('Location: dashboard.php?error=access_denied');
    exit;
}
$db = Database::getInstance();

$pending_count = (int)($db->getSingle("SELECT COUNT(*) cnt FROM pending_users WHERE status='pending'")['cnt'] ?? 0);

$pending_batches = (int)($db->getSingle(
    "SELECT COUNT(*) c FROM collection_batches WHERE status IN ('submitted','open')")['c'] ?? 0);

$pending_amount = (float)($db->getSingle(
    "SELECT COALESCE(SUM(total_amount),0) s FROM collection_batches WHERE status IN ('submitted','open')")['s'] ?? 0);

$remitted_today = (int)($db->getSingle(
    "SELECT COUNT(*) c FROM collection_batches 
     WHERE status='remitted' AND DATE(remitted_at)=CURDATE()")['c'] ?? 0);

$batches = $db->getAll("
    SELECT b.*, u.full_name AS leader_name, g.group_name, c.chapter_name
    FROM collection_batches b
    JOIN users u   ON u.user_id  = b.leader_user_id
    JOIN groups g  ON g.group_id = b.group_id
    LEFT JOIN chapters c ON c.chapter_id = g.chapter_id
    WHERE b.status IN ('submitted','open')
    ORDER BY b.created_at DESC
");

$recent = $db->getAll("
    SELECT b.*, u.full_name AS leader_name, g.group_name,
           a.full_name AS remitted_to_name
    FROM collection_batches b
    JOIN users u   ON u.user_id  = b.leader_user_id
    JOIN groups g  ON g.group_id = b.group_id
    LEFT JOIN users a ON a.user_id = b.remitted_to
    WHERE b.status = 'remitted'
    ORDER BY b.remitted_at DESC
    LIMIT 20
");

$csrf_token = Security::generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Remittances — <?php echo APP_NAME; ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
<style>
*{box-sizing:border-box;}
body{background:#f0f2f5;font-family:'Segoe UI',Tahoma,sans-serif;overflow:hidden;margin:0;}
#wrapper{display:flex;width:100%;height:100vh;overflow:hidden;}
#sidebar-wrapper{background:#1c1c1d;color:#fff;width:250px;height:100vh;overflow-y:auto;transition:width .3s;flex-shrink:0;white-space:nowrap;}
#sidebar-wrapper.collapsed{width:70px;}
#sidebar-wrapper.collapsed .list-group-item span{display:none;}
#sidebar-wrapper.collapsed .list-group-item{text-align:center;padding:15px 0;justify-content:center;}
#sidebar-wrapper.collapsed .list-group-item i{margin-right:0;width:100%;text-align:center;font-size:1.2rem;}
#sidebar-wrapper .sidebar-heading{padding:1rem 1rem .85rem;font-size:1.1rem;font-weight:700;border-bottom:1px solid rgba(255,255,255,.1);display:flex;align-items:center;gap:10px;line-height:1.15;background:#1c1c1d;}
#sidebar-wrapper .sidebar-heading .sidebar-logo{height:34px;width:34px;object-fit:contain;flex-shrink:0;}
#sidebar-wrapper .sidebar-heading .brand-text{display:flex;flex-direction:column;text-align:left;flex:1;min-width:0;}
#sidebar-wrapper .sidebar-heading .brand-text .brand-acronym{font-size:1.15rem;letter-spacing:.5px;font-weight:800;}
#sidebar-wrapper .sidebar-heading .brand-text .brand-full{font-size:.62rem;font-weight:400;color:rgba(255,255,255,.7);letter-spacing:.2px;text-transform:uppercase;margin-top:1px;white-space:normal;line-height:1.15;max-width:175px;}
#sidebar-wrapper.collapsed .sidebar-heading .brand-text,
#sidebar-wrapper.collapsed .sidebar-heading .sidebar-logo{display:none;}
#sidebar-wrapper.collapsed .sidebar-heading{justify-content:center;padding:1rem 0;gap:0;}
#sidebar-wrapper .list-group-item{background:transparent;border:none;color:rgba(255,255,255,.9);padding:.8rem 1.2rem;font-weight:500;transition:all .2s;font-size:.95rem;text-align:left;display:flex;align-items:center;gap:10px;}
#sidebar-wrapper .list-group-item i{width:24px;text-align:center;font-size:1rem;flex-shrink:0;}
#sidebar-wrapper .list-group-item:hover,
#sidebar-wrapper .list-group-item.active{background:rgba(255,255,255,.15);color:#fff;border-left:4px solid #fff;}
#sidebar-wrapper.collapsed .list-group-item:hover,
#sidebar-wrapper.collapsed .list-group-item.active{border-left:none;border-bottom:2px solid #fff;}
#sidebar-wrapper .list-group-item .badge-count{margin-left:auto;font-size:.65rem;padding:3px 7px;line-height:1;border-radius:10px;flex-shrink:0;}
#sidebar-wrapper.collapsed .badge-count{display:none !important;}
.menu-toggle{background:transparent;border:none;color:white;font-size:1.5rem;cursor:pointer;padding:0 10px;flex-shrink:0;transition:all .2s;}
.menu-toggle:hover{transform:scale(1.1);}

#page-content-wrapper{flex:1;background:#f0f2f5;height:100vh;overflow-y:auto;padding:0;min-width:0;}
.navbar{background:#fff !important;box-shadow:0 2px 4px rgba(0,0,0,.05);padding:.7rem 1.2rem;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:100;}
.navbar-left{display:flex;align-items:center;gap:8px;}
.navbar-brand{font-size:1.2rem;font-weight:500;color:#1c1c1d !important;display:flex;align-items:center;}
.navbar-right{display:flex;align-items:center;gap:20px;}
.header-logo{height:30px;width:auto;margin-right:10px;display:none;}
#sidebar-wrapper.collapsed ~ #page-content-wrapper .header-logo{display:inline-block;}

.container-fluid{padding:1.25rem;}
.stat-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1rem;margin-bottom:1.5rem;}
.stat-card{background:#fff;border-radius:14px;padding:1.25rem 1.5rem;box-shadow:0 1px 3px rgba(0,0,0,.05);display:flex;align-items:center;gap:16px;}
.stat-card .ic{width:54px;height:54px;border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:1.4rem;flex-shrink:0;background:#e8f0fe;color:#1a56db;}
.stat-card .ic.gold{background:#fef3c7;color:#d97706;}
.stat-card .ic.green{background:#dcfce7;color:#16a34a;}
.stat-card .val{font-size:1.5rem;font-weight:800;color:#1e2b3c;line-height:1.1;}
.stat-card .lbl{font-size:.82rem;color:#64748b;font-weight:600;margin-top:4px;}

.scan-hero{background:linear-gradient(135deg,#1e3a5a 0%,#2d5f92 100%);color:#fff;border-radius:18px;padding:2rem;margin-bottom:1.5rem;display:flex;align-items:center;justify-content:space-between;gap:1.5rem;flex-wrap:wrap;box-shadow:0 8px 22px rgba(26,58,92,.25);position:relative;overflow:hidden;}
.scan-hero::before{content:'';position:absolute;top:-40px;right:-40px;width:220px;height:220px;background:radial-gradient(circle,rgba(251,191,36,.25) 0%,transparent 70%);border-radius:50%;pointer-events:none;}
.scan-hero .txt{position:relative;z-index:2;}
.scan-hero h3{margin:0 0 .5rem;font-weight:800;font-size:1.35rem;display:flex;align-items:center;gap:10px;color:#fff;}
.scan-hero p{margin:0;opacity:.9;font-size:.95rem;max-width:640px;line-height:1.55;}
.btn-scan{background:linear-gradient(135deg,#fbbf24 0%,#f59e0b 100%);color:#1e3a5a;border:none;padding:1rem 1.5rem;border-radius:100px;font-weight:800;font-size:.95rem;display:inline-flex;align-items:center;gap:10px;cursor:pointer;box-shadow:0 6px 20px rgba(251,191,36,.4);transition:all .2s;white-space:nowrap;position:relative;z-index:2;}
.btn-scan:hover{transform:translateY(-2px);box-shadow:0 10px 26px rgba(251,191,36,.55);color:#1e3a5a;}
.btn-scan i{font-size:1.05rem;}
.btn-scan:disabled{opacity:.6;cursor:not-allowed;transform:none;}

.section{background:#fff;border-radius:14px;box-shadow:0 1px 3px rgba(0,0,0,.05);margin-bottom:1.5rem;overflow:hidden;}
.section-head{padding:.85rem 1.25rem;border-bottom:1px solid #eef2f7;display:flex;align-items:center;justify-content:space-between;background:#fbfcfe;}
.section-head h5{margin:0;font-size:1rem;font-weight:700;color:#1e2b3c;display:flex;align-items:center;gap:10px;}
.section-head h5 i{color:#2d5f92;}
.section-body{padding:1.25rem;}
.table-wrap{overflow-x:auto;}

table.tx{width:100%;border-collapse:collapse;font-size:.9rem;}
table.tx th{background:#f8fafc;text-align:left;padding:.85rem 1rem;font-size:.72rem;text-transform:uppercase;letter-spacing:.05em;color:#64748b;font-weight:700;border-bottom:1px solid #e2e8f0;white-space:nowrap;}
table.tx td{padding:.85rem 1rem;border-bottom:1px solid #f1f5f9;vertical-align:middle;}
table.tx tr:last-child td{border-bottom:none;}
table.tx tr:hover td{background:#fafbfd;}
.badge-status{display:inline-flex;align-items:center;gap:6px;font-size:.72rem;font-weight:700;padding:4px 10px;border-radius:100px;border:1px solid transparent;}
.badge-status.submitted{background:#fff8e1;color:#78350f;border-color:#fde68a;}
.badge-status.open{background:#e8f0fe;color:#1e40af;border-color:#bfdbfe;}
.badge-status.remitted{background:#dcfce7;color:#14532d;border-color:#bbf7d0;}

.amount{font-weight:800;color:#1e2b3c;font-variant-numeric:tabular-nums;}
.btn-action{display:inline-flex;align-items:center;gap:6px;padding:6px 14px;border-radius:8px;font-size:.82rem;font-weight:700;border:1px solid transparent;cursor:pointer;text-decoration:none;transition:all .15s;}
.btn-action.primary{background:#1e3a5a;color:#fff;}
.btn-action.primary:hover{background:#2d5f92;color:#fff;}

.empty-state{text-align:center;padding:3rem 1rem;color:#64748b;}
.empty-state i{font-size:2.5rem;color:#cbd5e1;display:block;margin-bottom:.75rem;}

#scanModal .modal-content{border:none;border-radius:16px;overflow:hidden;}
#scanModal .modal-header{background:#1c1c1d;color:#fff;border:none;padding:1rem 1.25rem;}
#scanModal .modal-header .btn-close{filter:invert(1);}
#qr-reader-wrap{position:relative;background:#000;border-radius:10px;overflow:hidden;aspect-ratio:1/1;max-height:60vh;margin:0 auto;}
#qr-reader{width:100% !important;height:100% !important;border:none !important;}
#qr-reader video{width:100% !important;height:100% !important;object-fit:cover;}
#qr-reader__scan_region{border:none !important;}
#qr-reader__dashboard{display:none !important;}
#qr-reader__header_message{display:none !important;}
#qr-reader__status_span{display:none !important;}
#qr-reader__filescan_input{display:none !important;}
.scan-status{margin-top:1rem;padding:1rem;border-radius:10px;background:#f8fafc;text-align:center;font-size:.9rem;color:#64748b;font-weight:600;}
.scan-status.error{background:#fdecee;color:#842029;}
.scan-status.success{background:#dcfce7;color:#14532d;}

.confirm-summary{background:#f8fafc;border-radius:10px;padding:1rem 1.25rem;margin-bottom:1rem;}
.confirm-row{display:flex;justify-content:space-between;padding:.45rem 0;font-size:.9rem;border-bottom:1px dashed #e2e8f0;}
.confirm-row:last-child{border-bottom:none;}
.confirm-row .lbl{color:#64748b;font-weight:600;}
.confirm-row .val{color:#1e2b3c;font-weight:700;text-align:right;}
.confirm-items{max-height:340px;overflow-y:auto;border:1px solid #e2e8f0;border-radius:10px;}
.confirm-items table{width:100%;border-collapse:collapse;font-size:.86rem;}
.confirm-items th{background:#f8fafc;padding:.6rem .85rem;text-align:left;font-size:.7rem;text-transform:uppercase;color:#64748b;font-weight:700;position:sticky;top:0;z-index:1;}
.confirm-items td{padding:.6rem .85rem;border-top:1px solid #f1f5f9;}
.confirm-items td.period-cell{font-weight:700;color:#1e3a5a;font-variant-numeric:tabular-nums;white-space:nowrap;}
.confirm-items td.collected-cell{color:#64748b;font-size:.78rem;white-space:nowrap;}

@media (max-width:768px){
  #sidebar-wrapper{position:fixed;left:0;top:0;transform:translateX(-100%);z-index:1050;transition:transform .3s;}
  #sidebar-wrapper.mobile-open{transform:translateX(0);}
  #sidebar-wrapper .sidebar-heading .brand-text,
  #sidebar-wrapper .sidebar-heading .sidebar-logo{display:flex;}
  .header-logo{display:inline-block !important;}
  .stat-row{grid-template-columns:1fr;}
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
            <a href="remittances.php" class="list-group-item active"><i class="fas fa-hand-holding-usd"></i><span>Remittances</span></a>
           <a href="reports.php" class="list-group-item"><i class="fas fa-chart-bar"></i><span>Reports</span></a>
            <a href="announcements.php" class="list-group-item"><i class="fas fa-bullhorn"></i><span>Send Announcement</span></a>
            <?php if ($current_user['role'] === 'admin'): ?>
            <a href="pending_users.php" class="list-group-item">
                <i class="fas fa-user-clock"></i><span>Pending</span>
                <?php if ($pending_count > 0): ?><span class="badge bg-danger badge-count"><?php echo $pending_count; ?></span><?php endif; ?>
            </a>
            <?php endif; ?>
            
            
            <a href="settings.php" class="list-group-item"><i class="fas fa-cog"></i><span>Settings</span></a>
            <a href="../logout.php" class="list-group-item" onclick="return confirm('Are you sure?');"><i class="fas fa-sign-out-alt"></i><span>Logout</span></a>
        </div>
    </div>

    <!-- Page -->
    <div id="page-content-wrapper">
        <nav class="navbar navbar-light bg-light">
            <div class="navbar-left">
                <img src="../assets/images/harana-logo.png" alt="Harana" class="header-logo" onerror="this.style.display='none';">
                <span class="navbar-brand"><i class="fas fa-hand-holding-usd me-2"></i>Remittances</span>
            </div>
            <div class="navbar-right">
                <span class="text-muted small"><i class="fas fa-calendar-alt me-1"></i><?php echo date('F j, Y'); ?></span>
                <span class="small"><i class="fas fa-user-circle me-2"></i><?php echo htmlspecialchars($current_user['full_name'] ?? $current_user['username']); ?></span>
            </div>
        </nav>

        <div class="container-fluid">

            <div class="stat-row">
                <div class="stat-card">
                    <div class="ic"><i class="fas fa-hourglass-half"></i></div>
                    <div>
                        <div class="val"><?php echo number_format($pending_batches); ?></div>
                        <div class="lbl">Pending batches</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="ic gold"><i class="fas fa-peso-sign"></i></div>
                    <div>
                        <div class="val">₱<?php echo number_format($pending_amount, 2); ?></div>
                        <div class="lbl">Pending amount</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="ic green"><i class="fas fa-check-double"></i></div>
                    <div>
                        <div class="val"><?php echo $remitted_today; ?></div>
                        <div class="lbl">Remitted today</div>
                    </div>
                </div>
            </div>

            <div class="scan-hero">
                <div class="txt">
                    <h3><i class="fas fa-qrcode"></i> Collect leader's remittance</h3>
                    <p>Scan the leader's QR with the camera, upload a screenshot, or paste the payload text. Then confirm to record the payments.</p>
                </div>
                <div style="display:flex; gap:10px; flex-wrap:wrap; position:relative; z-index:2;">
                    <button class="btn-scan" id="openScanBtn">
                        <i class="fas fa-camera"></i> Scan with Camera
                    </button>
                    <button class="btn-scan" id="openUploadBtn" style="background:linear-gradient(135deg,#7ee787 0%,#22c55e 100%);">
                        <i class="fas fa-upload"></i> Upload QR Image
                    </button>
                    <button class="btn-scan" id="openPasteBtn" style="background:linear-gradient(135deg,#a5b4fc 0%,#6366f1 100%); color:#fff;">
                        <i class="fas fa-paste"></i> Paste Payload
                    </button>
                </div>
            </div>

            <div class="section">
                <div class="section-head">
                    <h5><i class="fas fa-hourglass-half"></i> Pending remittance batches</h5>
                    <span class="text-muted small"><?php echo count($batches); ?> batch<?php echo count($batches) === 1 ? '' : 'es'; ?></span>
                </div>
                <div class="section-body p-0">
                    <div class="table-wrap">
                        <table class="tx">
                            <thead>
                                <tr>
                                    <th>Batch ID</th>
                                    <th>Leader</th>
                                    <th>Group</th>
                                    <th>Period</th>
                                    <th>Payments</th>
                                    <th>Total</th>
                                    <th>Status</th>
                                    <th>Submitted</th>
                                    <th style="text-align:right;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (empty($batches)): ?>
                                <tr><td colspan="9"><div class="empty-state"><i class="fas fa-inbox"></i>No pending batches. All caught up.</div></td></tr>
                            <?php else: foreach ($batches as $b): ?>
                                <tr>
                                    <td><code style="font-size:.75rem;"><?php echo htmlspecialchars(substr($b['batch_id'],0,10)); ?>…</code></td>
                                    <td><strong><?php echo htmlspecialchars($b['leader_name']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($b['group_name']); ?></td>
                                    <td><?php echo date('M Y', strtotime($b['period_month'] . '-01')); ?></td>
                                    <td><?php echo (int)$b['member_count']; ?></td>
                                    <td class="amount">₱<?php echo number_format((float)$b['total_amount'], 2); ?></td>
                                    <td><span class="badge-status <?php echo $b['status']; ?>"><?php echo ucfirst($b['status']); ?></span></td>
                                    <td><small><?php echo date('M d, Y H:i', strtotime($b['created_at'])); ?></small></td>
                                    <td style="text-align:right;">
                                        <button class="btn-action primary" onclick='openConfirmModal(<?php echo json_encode([
                                            "batch_id" => $b["batch_id"],
                                            "leader"   => $b["leader_name"],
                                            "group"    => $b["group_name"],
                                            "total"    => (float)$b["total_amount"],
                                            "count"    => (int)$b["member_count"],
                                            "items"    => null
                                        ], JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'>
                                            <i class="fas fa-check"></i> Confirm
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="section">
                <div class="section-head">
                    <h5><i class="fas fa-history"></i> Recently remitted</h5>
                    <span class="text-muted small">Last 20 batches</span>
                </div>
                <div class="section-body p-0">
                    <div class="table-wrap">
                        <table class="tx">
                            <thead>
                                <tr>
                                    <th>Batch ID</th>
                                    <th>Leader</th>
                                    <th>Group</th>
                                    <th>Period</th>
                                    <th>Total</th>
                                    <th>Remitted</th>
                                    <th>Confirmed by</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (empty($recent)): ?>
                                <tr><td colspan="7"><div class="empty-state"><i class="fas fa-history"></i>No remittances yet.</div></td></tr>
                            <?php else: foreach ($recent as $b): ?>
                                <tr>
                                    <td><code style="font-size:.75rem;"><?php echo htmlspecialchars(substr($b['batch_id'],0,10)); ?>…</code></td>
                                    <td><?php echo htmlspecialchars($b['leader_name']); ?></td>
                                    <td><?php echo htmlspecialchars($b['group_name']); ?></td>
                                    <td><?php echo date('M Y', strtotime($b['period_month'] . '-01')); ?></td>
                                    <td class="amount">₱<?php echo number_format((float)$b['total_amount'], 2); ?></td>
                                    <td><small><?php echo date('M d, Y H:i', strtotime($b['remitted_at'])); ?></small></td>
                                    <td><small><?php echo htmlspecialchars($b['remitted_to_name'] ?? '—'); ?></small></td>
                                </tr>
                            <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<!-- Scan QR Modal -->
<div class="modal fade" id="scanModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-camera me-2"></i>Scan Leader QR</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="qr-reader-wrap">
                    <div id="qr-reader"></div>
                </div>
                <div class="scan-status" id="scanStatus">
                    <i class="fas fa-camera me-2"></i>Point the camera at the leader's QR code.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            </div>
        </div>
    </div>
</div>

<!-- Confirm Remittance Modal -->
<div class="modal fade" id="confirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title"><i class="fas fa-check-circle me-2"></i>Confirm Remittance</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="confirm-summary">
                    <div class="confirm-row"><span class="lbl">Leader</span><span class="val" id="cm-leader">—</span></div>
                    <div class="confirm-row"><span class="lbl">Group</span><span class="val" id="cm-group">—</span></div>
                    <div class="confirm-row"><span class="lbl">Payments</span><span class="val" id="cm-count">—</span></div>
                    <div class="confirm-row"><span class="lbl">Members</span><span class="val" id="cm-members">—</span></div>
                    <div class="confirm-row"><span class="lbl">Total collected</span><span class="val" id="cm-total">—</span></div>
                </div>

                <div style="font-size:.78rem;font-weight:700;text-transform:uppercase;color:#64748b;margin-bottom:.5rem;">
                    <i class="fas fa-list me-1"></i> Member payments
                </div>
                <div class="confirm-items" id="cm-items">
                    <div class="text-center p-3 text-muted"><i class="fas fa-spinner fa-spin"></i> Loading…</div>
                </div>

                <div class="mt-3">
                    <label class="form-label small fw-bold">Cash received <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <span class="input-group-text">₱</span>
                        <input type="number" step="0.01" class="form-control" id="cm-received" placeholder="0.00">
                    </div>
                    <div class="form-text">Enter the amount of cash the leader physically handed over.</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-success" id="cm-confirmBtn">
                    <i class="fas fa-check me-1"></i> Confirm Remittance
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Paste Payload Modal -->
<div class="modal fade" id="pasteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title"><i class="fas fa-paste me-2"></i>Paste QR Payload</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="small text-muted">
                    Ask the leader to copy the payload text from their app. It looks like:
                    <code>{"v":1,"b":"...","t":"...","s":"..."}</code>
                </p>
                <label class="form-label small fw-bold">Payload JSON</label>
                <textarea id="pastePayload" class="form-control" rows="4"
                          placeholder='{"v":1,"b":"...","t":"...","s":"..."}'
                          style="font-family:monospace; font-size:0.85rem;"></textarea>
                <div id="pasteStatus" class="mt-2 small"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-success" id="pasteVerifyBtn">
                    <i class="fas fa-check me-1"></i> Verify &amp; Open
                </button>
            </div>
        </div>
    </div>
</div>

<input type="file" id="qrFileInput" accept="image/*" style="display:none;">
<div id="qr-reader-hidden" style="display:none; width:0; height:0; overflow:hidden;"></div>

<div id="uploadToast" style="
    display:none;
    position: fixed;
    top: 90px;
    right: 20px;
    z-index: 99999;
    padding: 14px 20px;
    border-radius: 10px;
    font-weight: 700;
    font-size: 0.92rem;
    box-shadow: 0 6px 20px rgba(0,0,0,0.15);
    max-width: 400px;
    line-height: 1.5;
"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script>
/* ---------- Sidebar ---------- */
const sidebar = document.getElementById('sidebar-wrapper');
document.getElementById('menuToggle')?.addEventListener('click', () => {
    sidebar.classList.toggle('collapsed');
    localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
});
if (localStorage.getItem('sidebarCollapsed') === 'true') sidebar.classList.add('collapsed');

/* ============================================================
   FORMAT HELPERS
============================================================ */
function fmtPeriod(ym) {
    // "2026-02" -> "Feb 2026"; fall back to raw if shape is unexpected
    if (!ym || !/^\d{4}-\d{2}$/.test(ym)) return ym || '—';
    const [y, m] = ym.split('-').map(Number);
    const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    return months[m - 1] + ' ' + y;
}

function fmtDateTime(s) {
    if (!s) return '—';
    const d = new Date(String(s).replace(' ', 'T'));
    if (isNaN(d.getTime())) return s;
    const pad = n => String(n).padStart(2, '0');
    return months[d.getMonth()] + ' ' + d.getDate() + ', ' + d.getFullYear() +
           ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes());
}
const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];

/* ============================================================
   CAMERA SCANNER
============================================================ */
let scanner = null;
let scanModal = null;
const scanStatus = document.getElementById('scanStatus');

function setStatus(msg, cls) {
    scanStatus.className = 'scan-status' + (cls ? ' ' + cls : '');
    scanStatus.innerHTML = msg;
}

document.getElementById('openScanBtn').addEventListener('click', async () => {
    scanModal = new bootstrap.Modal(document.getElementById('scanModal'));
    scanModal.show();
    setStatus('<i class="fas fa-spinner fa-spin me-2"></i>Starting camera…');

    setTimeout(async () => {
        try {
            scanner = new Html5Qrcode("qr-reader");
            await scanner.start(
                { facingMode: "environment" },
                { fps: 10, qrbox: { width: 250, height: 250 } },
                onScanSuccess,
                () => {}
            );
            setStatus('<i class="fas fa-camera me-2"></i>Point the camera at the leader\'s QR code.');
        } catch (e) {
            setStatus('<i class="fas fa-exclamation-circle me-2"></i>Camera error: ' + e, 'error');
        }
    }, 400);
});

document.getElementById('scanModal').addEventListener('hidden.bs.modal', async () => {
    if (scanner) {
        try { await scanner.stop(); await scanner.clear(); } catch (e) {}
        scanner = null;
    }
});

async function onScanSuccess(decodedText) {
    if (scanner) {
        try { await scanner.stop(); } catch (e) {}
    }
    if (scanStatus) setStatus('<i class="fas fa-spinner fa-spin me-2"></i>Verifying batch…');

    let payload;
    try {
        payload = JSON.parse(decodedText);
    } catch (e) {
        setStatus('<i class="fas fa-times-circle me-2"></i>Not a valid QR code.', 'error');
        return;
    }
    if (!payload.b || !payload.t || !payload.s) {
        setStatus('<i class="fas fa-times-circle me-2"></i>Invalid QR payload.', 'error');
        return;
    }

    try {
        const res = await fetch('api_batch_lookup.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
            credentials: 'same-origin'
        });
        const data = await res.json();

        if (!data.success) {
            setStatus('<i class="fas fa-times-circle me-2"></i>' + (data.error || 'Lookup failed.'), 'error');
            return;
        }

        setStatus('<i class="fas fa-check-circle me-2"></i>Batch verified. Opening confirmation…', 'success');

        if (scanModal) scanModal.hide();
        setTimeout(() => {
            openConfirmModal({
                batch_id: data.batch.batch_id,
                leader:   data.batch.leader_name,
                group:    data.batch.group_name,
                total:    parseFloat(data.batch.total_amount),
                count:    parseInt(data.batch.member_count),
                items:    data.items
            });
        }, 300);

    } catch (e) {
        setStatus('<i class="fas fa-exclamation-triangle me-2"></i>Network error: ' + e.message, 'error');
    }
}

/* ============================================================
   UPLOAD QR IMAGE — v2 with preprocessing + fallback decoders
============================================================ */
(function () {
    const uploadBtn  = document.getElementById('openUploadBtn');
    const fileInput  = document.getElementById('qrFileInput');
    const toast      = document.getElementById('uploadToast');

    if (!uploadBtn || !fileInput) return;

    let toastTimer = null;
    function showToast(msg, type) {
        toast.innerHTML = msg;
        toast.style.display = 'block';
        if (type === 'error') {
            toast.style.background = '#fdecee'; toast.style.color = '#842029'; toast.style.borderLeft = '5px solid #dc2626';
        } else if (type === 'success') {
            toast.style.background = '#dcfce7'; toast.style.color = '#14532d'; toast.style.borderLeft = '5px solid #16a34a';
        } else {
            toast.style.background = '#e8f0fe'; toast.style.color = '#1e40af'; toast.style.borderLeft = '5px solid #2d5f92';
        }
        if (toastTimer) clearTimeout(toastTimer);
        toastTimer = setTimeout(() => { toast.style.display = 'none'; }, type === 'error' ? 7000 : 3000);
    }

    uploadBtn.addEventListener('click', () => {
        fileInput.value = '';
        fileInput.click();
    });

    function preprocessImage(file, targetMinSize) {
        return new Promise((resolve, reject) => {
            const img = new Image();
            const url = URL.createObjectURL(file);
            img.onload = () => {
                URL.revokeObjectURL(url);
                try {
                    const scale = Math.max(1, targetMinSize / Math.min(img.width, img.height));
                    const w = Math.round(img.width  * scale);
                    const h = Math.round(img.height * scale);
                    const pad = 40;
                    const canvas = document.createElement('canvas');
                    canvas.width  = w + pad * 2;
                    canvas.height = h + pad * 2;
                    const ctx = canvas.getContext('2d');
                    ctx.fillStyle = '#ffffff';
                    ctx.fillRect(0, 0, canvas.width, canvas.height);
                    ctx.drawImage(img, pad, pad, w, h);
                    resolve(canvas.toDataURL('image/png'));
                } catch (e) { reject(e); }
            };
            img.onerror = () => { URL.revokeObjectURL(url); reject(new Error('Invalid image file')); };
            img.src = url;
        });
    }

    function tryScan(dataUrl) {
        return new Promise(async (resolve) => {
            try {
                const tempScanner = new Html5Qrcode("qr-reader-hidden", false);
                try {
                    const text = await tempScanner.scanFile(dataUrl, false);
                    await tempScanner.clear();
                    resolve({ ok: true, text });
                } catch (e) {
                    try { await tempScanner.clear(); } catch (x) {}
                    resolve({ ok: false, err: e });
                }
            } catch (e) { resolve({ ok: false, err: e }); }
        });
    }

    fileInput.addEventListener('change', async function () {
        const file = this.files && this.files[0];
        if (!file) return;

        if (!file.type.startsWith('image/')) {
            showToast('This file is not an image (type: ' + (file.type || 'unknown') + ').<br>' +
                     '<small>Make sure you downloaded the QR as PNG or JPG — not the HTML page.</small>', 'error');
            fileInput.value = '';
            return;
        }
        if (file.size > 10 * 1024 * 1024) {
            showToast('Image is larger than 10MB.', 'error');
            fileInput.value = '';
            return;
        }

        showToast('<i class="fas fa-spinner fa-spin me-1"></i> Decoding QR from image…');

        if (typeof scanner !== 'undefined' && scanner) {
            try { await scanner.stop(); } catch (e) {}
            try { await scanner.clear(); } catch (e) {}
            scanner = null;
        }

        try {
            const rawDataUrl = await new Promise((resolve, reject) => {
                const r = new FileReader();
                r.onload = e => resolve(e.target.result);
                r.onerror = reject;
                r.readAsDataURL(file);
            });

            let result = await tryScan(rawDataUrl);
            if (result.ok) return handleDecoded(result.text);

            const pre = await preprocessImage(file, 600);
            result = await tryScan(pre);
            if (result.ok) return handleDecoded(result.text);

            const pre2 = await preprocessImage(file, 900);
            result = await tryScan(pre2);
            if (result.ok) return handleDecoded(result.text);

            const detail = (result.err && result.err.message) ? result.err.message : 'No QR code found in image.';
            showToast(
                '<strong>Could not read QR from image.</strong><br>' +
                '<small>' + detail + '</small><br><br>' +
                '<small><strong>Try:</strong></small><br>' +
                '<small>• Make sure the file is a real PNG/JPG (open it — if you see a webpage, re-download)</small><br>' +
                '<small>• Screenshot the QR directly instead of saving it</small><br>' +
                '<small>• Use <strong>Paste Payload</strong> as an alternative</small>',
                'error'
            );
        } catch (err) {
            showToast('<strong>Upload failed:</strong> ' + (err.message || 'unknown error'), 'error');
        } finally {
            fileInput.value = '';
        }
    });

    function handleDecoded(text) {
        showToast('<i class="fas fa-check-circle me-1"></i> QR decoded! Verifying batch…', 'success');
        if (typeof onScanSuccess === 'function') {
            onScanSuccess(text);
        } else {
            showToast('Internal error: scan handler missing.', 'error');
        }
    }
})();

/* ============================================================
   PASTE PAYLOAD
============================================================ */
document.getElementById('openPasteBtn')?.addEventListener('click', () => {
    document.getElementById('pastePayload').value = '';
    document.getElementById('pasteStatus').innerHTML = '';
    new bootstrap.Modal(document.getElementById('pasteModal')).show();
});

document.getElementById('pasteVerifyBtn')?.addEventListener('click', async function () {
    const raw = document.getElementById('pastePayload').value.trim();
    const status = document.getElementById('pasteStatus');
    status.innerHTML = '';

    if (!raw) {
        status.innerHTML = '<span class="text-danger">Paste the payload first.</span>';
        return;
    }

    let payload;
    try {
        payload = JSON.parse(raw);
    } catch (e) {
        status.innerHTML = '<span class="text-danger">Not valid JSON: ' + e.message + '</span>';
        return;
    }
    if (!payload.b || !payload.t || !payload.s) {
        status.innerHTML = '<span class="text-danger">Payload missing one of: b, t, s.</span>';
        return;
    }

    const btn = this;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Verifying…';

    try {
        const res = await fetch('api_batch_lookup.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
            credentials: 'same-origin'
        });
        const data = await res.json();

        if (!data.success) {
            status.innerHTML = '<span class="text-danger">' + (data.error || 'Lookup failed.') + '</span>';
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-check me-1"></i> Verify &amp; Open';
            return;
        }

        bootstrap.Modal.getInstance(document.getElementById('pasteModal')).hide();

        setTimeout(() => {
            openConfirmModal({
                batch_id: data.batch.batch_id,
                leader:   data.batch.leader_name,
                group:    data.batch.group_name,
                total:    parseFloat(data.batch.total_amount),
                count:    parseInt(data.batch.member_count),
                items:    data.items
            });
        }, 300);

    } catch (e) {
        status.innerHTML = '<span class="text-danger">Network error: ' + e.message + '</span>';
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-check me-1"></i> Verify &amp; Open';
    }
});

/* ============================================================
   CONFIRM REMITTANCE MODAL
============================================================ */
let currentBatch = null;
let confirmModalInstance = null;

/** Build the HTML rows for the member-payments table. */
function renderItemsTable(items) {
    let h = '<table><thead><tr>' +
            '<th>Member</th>' +
            '<th>Code</th>' +
            '<th>Period</th>' +
            '<th style="text-align:right;">Amount</th>' +
            '<th>Collected at</th>' +
            '</tr></thead><tbody>';
    items.forEach(it => {
        h += '<tr>' +
            '<td>' + escapeHtml(it.member_name || '') + '</td>' +
            '<td><code>' + escapeHtml(it.member_code) + '</code></td>' +
            '<td class="period-cell">' + escapeHtml(fmtPeriod(it.period_month)) + '</td>' +
            '<td style="text-align:right;font-weight:700;">₱' + parseFloat(it.amount).toFixed(2) + '</td>' +
            '<td class="collected-cell">' + escapeHtml(fmtDateTime(it.collected_at)) + '</td>' +
            '</tr>';
    });
    h += '</tbody></table>';
    return h;
}

/** Count distinct member_codes in an array of items. */
function countUniqueMembers(items) {
    if (!items || !items.length) return 0;
    const set = new Set();
    items.forEach(it => { if (it.member_code) set.add(it.member_code); });
    return set.size;
}

function openConfirmModal(batch) {
    currentBatch = batch;

    document.getElementById('cm-leader').textContent = batch.leader;
    document.getElementById('cm-group').textContent  = batch.group;
    document.getElementById('cm-count').textContent  = batch.count + ' payment' + (batch.count === 1 ? '' : 's');
    document.getElementById('cm-members').textContent = '—';
    document.getElementById('cm-total').textContent  = '₱' + batch.total.toLocaleString('en-PH', { minimumFractionDigits: 2 });
    document.getElementById('cm-received').value     = batch.total.toFixed(2);

    const wrap = document.getElementById('cm-items');

    if (batch.items && batch.items.length) {
        wrap.innerHTML = renderItemsTable(batch.items);

        const unique = countUniqueMembers(batch.items);
        document.getElementById('cm-members').textContent =
            unique + ' member' + (unique === 1 ? '' : 's');
    } else {
        wrap.innerHTML = '<div class="text-center p-3 text-muted"><i class="fas fa-spinner fa-spin"></i> Loading…</div>';
        fetch('api_batch_items.php?batch_id=' + encodeURIComponent(batch.batch_id), { credentials:'same-origin' })
            .then(r => r.json())
            .then(d => {
                if (d.success && d.items.length) {
                    wrap.innerHTML = renderItemsTable(d.items);

                    const unique = countUniqueMembers(d.items);
                    document.getElementById('cm-members').textContent =
                        unique + ' member' + (unique === 1 ? '' : 's');
                } else {
                    wrap.innerHTML = '<div class="text-center p-3 text-muted">No items found.</div>';
                    document.getElementById('cm-members').textContent = '—';
                }
            })
            .catch(() => {
                wrap.innerHTML = '<div class="text-center p-3 text-danger">Failed to load items.</div>';
                document.getElementById('cm-members').textContent = '—';
            });
    }

    confirmModalInstance = new bootstrap.Modal(document.getElementById('confirmModal'));
    confirmModalInstance.show();
}

document.getElementById('cm-confirmBtn').addEventListener('click', async function () {
    if (!currentBatch) return;

    const received = parseFloat(document.getElementById('cm-received').value);
    if (isNaN(received) || received <= 0) {
        alert('Please enter a valid amount.');
        return;
    }
    if (Math.abs(received - currentBatch.total) > 0.01) {
        if (!confirm('Cash received (₱' + received.toFixed(2) + ') does not match the expected total (₱' + currentBatch.total.toFixed(2) + '). Continue anyway?')) {
            return;
        }
    }

    const btn = this;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Confirming…';

    try {
        const res = await fetch('api_batch_confirm.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({
                batch_id: currentBatch.batch_id,
                received_amount: received,
                csrf_token: '<?php echo $csrf_token; ?>'
            })
        });
        const data = await res.json();

        if (data.success) {
            alert('Remittance confirmed! ' + data.items_confirmed + ' member payment(s) recorded.');
            location.reload();
        } else {
            alert('Failed: ' + (data.error || 'unknown error'));
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-check me-1"></i> Confirm Remittance';
        }
    } catch (e) {
        alert('Network error: ' + e.message);
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-check me-1"></i> Confirm Remittance';
    }
});

function escapeHtml(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({
        '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
    }[c]));
}
</script>
</body>
</html>