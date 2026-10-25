<?php
// admin/gcash_settings.php
require_once '../includes/config.php';
require_once '../includes/db_connection.php';
require_once '../includes/security.php';
require_once '../includes/auth.php';
require_once '../includes/user_functions.php';

if (!function_exists('getAppSetting')) {
    function getAppSetting($db, $key, $default = null) {
        try {
            $row = $db->getSingle(
                "SELECT setting_value FROM app_settings WHERE setting_key = ?",
                [$key], 's'
            );
            return $row['setting_value'] ?? $default;
        } catch (Throwable $e) {
            return $default;
        }
    }
}

$auth->requireLogin();
$current_user = $auth->getCurrentUser();
$db = Database::getInstance();

/* Pending users badge */
$pending_count = 0;
if ($current_user['role'] === 'admin') {
    $pending_count = $db->getSingle("SELECT COUNT(*) as cnt FROM pending_users WHERE status = 'pending'")['cnt'] ?? 0;
}

$csrf_token = Security::generateCSRFToken();

// Load current GCash settings
$gcash_qr     = getAppSetting($db, 'gcash_qr_path');
$gcash_mobile = getAppSetting($db, 'gcash_mobile',  '0928867551');
$gcash_name   = getAppSetting($db, 'gcash_name',    'RO***LE');
$gcash_userid = getAppSetting($db, 'gcash_user_id', '4LFEZX');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GCash Settings - <?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
    * { box-sizing: border-box; }
    body {
        background: #f0f2f5;
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        overflow: hidden;
        margin: 0;
        padding: 0;
    }
    #wrapper { display: flex; width: 100%; height: 100vh; overflow: hidden; }

    /* SIDEBAR */
    #sidebar-wrapper {
        background: #1c1c1d; color: #fff; width: 250px; height: 100vh;
        overflow-y: auto; transition: width 0.3s ease;
        box-shadow: 4px 0 10px rgba(0,0,0,0.1);
        white-space: nowrap; flex-shrink: 0;
    }
    #sidebar-wrapper.collapsed { width: 70px; }
    #sidebar-wrapper.collapsed .list-group-item span { display: none; }
    #sidebar-wrapper.collapsed .list-group-item {
        text-align: center; padding: 15px 0; justify-content: center;
    }
    #sidebar-wrapper.collapsed .list-group-item i {
        margin-right: 0; width: 100%; text-align: center; font-size: 1.2rem;
    }
    #sidebar-wrapper .sidebar-heading {
        padding: 1rem 1rem .85rem; font-size: 1.1rem; font-weight: 700;
        border-bottom: 1px solid rgba(255,255,255,.1);
        background: #1c1c1d; color: white;
        display: flex; align-items: center; gap: 10px; line-height: 1.15;
    }
    #sidebar-wrapper .sidebar-heading .sidebar-logo {
        height: 34px; width: 34px; object-fit: contain; flex-shrink: 0;
    }
    #sidebar-wrapper .sidebar-heading .brand-text {
        display: flex; flex-direction: column; text-align: left;
        flex: 1; min-width: 0;
    }
    #sidebar-wrapper .sidebar-heading .brand-text .brand-acronym {
        font-size: 1.15rem; letter-spacing: .5px; font-weight: 800;
    }
    #sidebar-wrapper .sidebar-heading .brand-text .brand-full {
        font-size: .62rem; font-weight: 400;
        color: rgba(255,255,255,.7); letter-spacing: .2px;
        text-transform: uppercase; margin-top: 1px;
        white-space: normal; line-height: 1.15; max-width: 175px;
    }
    #sidebar-wrapper.collapsed .sidebar-heading .brand-text { display: none; }
    #sidebar-wrapper.collapsed .sidebar-heading .sidebar-logo { display: none; }
    #sidebar-wrapper.collapsed .sidebar-heading {
        justify-content: center; padding: 1rem 0; gap: 0;
    }
    #sidebar-wrapper .list-group-item {
        background: transparent; border: none;
        color: rgba(255,255,255,.9);
        padding: .8rem 1.2rem; font-weight: 500;
        transition: all .2s; font-size: .95rem; text-align: left;
        display: flex; align-items: center; gap: 10px;
    }
    #sidebar-wrapper .list-group-item i {
        width: 24px; text-align: center; font-size: 1rem; flex-shrink: 0;
    }
    #sidebar-wrapper .list-group-item:hover,
    #sidebar-wrapper .list-group-item.active {
        background: rgba(255,255,255,.15); color: #fff;
        border-left: 4px solid #fff;
    }
    #sidebar-wrapper.collapsed .list-group-item:hover,
    #sidebar-wrapper.collapsed .list-group-item.active {
        border-left: none; border-bottom: 2px solid #fff;
    }
    #sidebar-wrapper .list-group-item .badge-count {
        margin-left: auto; font-size: .65rem; padding: 3px 7px;
        line-height: 1; border-radius: 10px; flex-shrink: 0;
    }
    #sidebar-wrapper.collapsed .badge-count { display: none !important; }

    .menu-toggle {
        background: transparent; border: none; color: white;
        font-size: 1.5rem; cursor: pointer; padding: 0 10px;
        flex-shrink: 0; transition: all .2s;
    }
    .menu-toggle:hover { transform: scale(1.1); }

    /* MAIN */
    #page-content-wrapper {
        flex: 1; background: #f0f2f5; height: 100vh;
        overflow-y: auto; padding: 0; min-width: 0;
    }
    .navbar {
        background: #fff !important; box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        padding: 0.7rem 1.2rem; display: flex; align-items: center;
        justify-content: space-between; position: sticky; top: 0; z-index: 100;
    }
    .navbar-left { display: flex; align-items: center; gap: 8px; }
    .navbar-brand {
        font-size: 1.2rem; font-weight: 500;
        color: #1c1c1d !important;
        display: flex; align-items: center;
    }
    .navbar-brand i { color: #1c1c1d; font-size: 1.2rem; }
    .navbar-right { display: flex; align-items: center; gap: 20px; }
    .header-logo {
        height: 30px; width: auto; margin-right: 10px;
        vertical-align: middle; display: none;
    }
    #sidebar-wrapper.collapsed ~ #page-content-wrapper .header-logo { display: inline-block; }

    /* Responsive */
    @media (max-width: 768px) {
        #sidebar-wrapper {
            position: fixed; left: 0; top: 0;
            transform: translateX(-100%);
            z-index: 1050; transition: transform .3s ease;
        }
        #sidebar-wrapper.mobile-open { transform: translateX(0); }
        #sidebar-wrapper .sidebar-heading .brand-text .brand-full { display: block; }
        .header-logo { display: inline-block !important; }
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
           <a href="leader_assignments.php" class="list-group-item <?php echo $current_page == 'leader_assignments.php' ? 'active' : ''; ?>"><i class="fas fa-user-shield"></i><span>Leader Assignment</span></a>
            <a href="council.php" class="list-group-item"><i class="fas fa-user-tie"></i><span>Council</span></a>
           <a href="payments.php" class="list-group-item active"><i class="fas fa-credit-card"></i><span>Payments</span></a>
            <a href="remittances.php" class="list-group-item"><i class="fas fa-hand-holding-usd"></i><span>Remittances</span></a>
            <!--<a href="gcash_settings.php" class="list-group-item active"><i class="fas fa-qrcode"></i><span>GCash Settings</span></a>-->
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
           
            <a href="settings.php" class="list-group-item"><i class="fas fa-cog"></i><span>Settings</span></a>
            <a href="../logout.php" class="list-group-item" onclick="return confirm('Are you sure?');"><i class="fas fa-sign-out-alt"></i><span>Logout</span></a>
        </div>
    </div>

    <!-- Page content -->
    <div id="page-content-wrapper">
        <nav class="navbar navbar-light bg-light">
            <div class="navbar-left">
                <img src="../assets/images/harana-logo.png" alt="Harana" class="header-logo" id="headerLogo" onerror="this.style.display='none';">
                <span class="navbar-brand"><i class="fas fa-qrcode me-2"></i>GCash Settings</span>
            </div>
            <div class="navbar-right">
                <a href="payments.php" class="btn btn-sm btn-outline-secondary">
                    <i class="fas fa-arrow-left me-1"></i>Back to Payments
                </a>
                <span class="text-muted small">
                    <i class="fas fa-calendar-alt me-1"></i><?php echo date('F j, Y'); ?>
                </span>
                <span class="small"><i class="fas fa-user-circle me-2"></i><?php echo htmlspecialchars($current_user['full_name'] ?? $current_user['username']); ?></span>
            </div>
        </nav>

        <div class="container-fluid p-3">

            <!-- ============================================================ -->
            <!-- GCash QR Settings Card                                       -->
            <!-- ============================================================ -->
            <div class="card shadow-sm mb-3" style="border-left: 4px solid #0070ba;">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0" style="font-weight: 600;">
                        <i class="fas fa-qrcode me-2" style="color: #0070ba;"></i>
                        GCash Payment QR Code
                    </h5>
                    <span class="badge bg-light text-dark" style="font-weight: 500;">
                        Visible to members on the payment page
                    </span>
                </div>
                <div class="card-body">
                    <div class="row g-3 align-items-center">
                        <div class="col-md-3 text-center">
                            <div id="qrPreviewWrap" style="
                                width: 180px; height: 180px; margin: 0 auto;
                                border: 2px dashed #dee2e6; border-radius: 10px;
                                display: flex; align-items: center; justify-content: center;
                                background: #fafbfc; overflow: hidden; position: relative;">
                                <?php if ($gcash_qr && file_exists('../' . $gcash_qr)): ?>
                                    <img id="qrPreview" src="../<?php echo htmlspecialchars($gcash_qr); ?>?v=<?php echo time(); ?>"
                                         alt="GCash QR"
                                         style="width: 100%; height: 100%; object-fit: contain;">
                                <?php else: ?>
                                    <div id="qrEmpty" style="color: #adb5bd; text-align: center;">
                                        <i class="fas fa-qrcode" style="font-size: 3rem;"></i>
                                        <div style="font-size: 0.75rem; margin-top: 6px;">No QR uploaded</div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="col-md-9">
                            <p class="text-muted small mb-3">
                                Upload the official GCash QR code of the organization.
                                This image will be displayed on the member's GCash payment page.
                                Members scan it with their real GCash app to send their contribution.
                            </p>

                            <div class="d-flex flex-wrap gap-2 mb-3">
                                <label for="qrFileInput" class="btn btn-primary btn-sm mb-0"
                                       style="background: #0070ba; border-color: #0070ba;">
                                    <i class="fas fa-upload me-1"></i>
                                    <?php echo $gcash_qr ? 'Change QR Code' : 'Upload QR Code'; ?>
                                </label>
                                <input type="file" id="qrFileInput" accept="image/jpeg,image/png,image/webp"
                                       style="display: none;">

                                <?php if ($gcash_qr): ?>
                                <button type="button" class="btn btn-outline-danger btn-sm" id="deleteQrBtn">
                                    <i class="fas fa-trash me-1"></i> Remove
                                </button>
                                <?php endif; ?>
                            </div>

                            <div class="row g-2" style="max-width: 520px;">
                                <div class="col-md-6">
                                    <label class="form-label small text-muted mb-1">GCash Number</label>
                                    <input type="text" class="form-control form-control-sm"
                                           id="gcashMobile" value="<?php echo htmlspecialchars($gcash_mobile); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small text-muted mb-1">Account Name</label>
                                    <input type="text" class="form-control form-control-sm"
                                           id="gcashName" value="<?php echo htmlspecialchars($gcash_name); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small text-muted mb-1">User ID (optional)</label>
                                    <input type="text" class="form-control form-control-sm"
                                           id="gcashUserId" value="<?php echo htmlspecialchars($gcash_userid); ?>">
                                </div>
                                <div class="col-md-6 d-flex align-items-end">
                                    <button type="button" class="btn btn-outline-secondary btn-sm w-100"
                                            id="saveGcashInfoBtn">
                                        <i class="fas fa-save me-1"></i> Save Account Info
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
/* Sidebar */
const sidebar = document.getElementById('sidebar-wrapper');
document.getElementById('menuToggle')?.addEventListener('click', () => {
    sidebar.classList.toggle('collapsed');
    localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
});
if (localStorage.getItem('sidebarCollapsed') === 'true') sidebar.classList.add('collapsed');

/* ============================================================
   GCash QR upload / delete / account info
============================================================ */
(function () {
    const csrfToken = <?php echo json_encode($csrf_token); ?>;

    // Upload QR
    const fileInput = document.getElementById('qrFileInput');
    fileInput?.addEventListener('change', function () {
        const file = this.files[0];
        if (!file) return;
        if (file.size > 5 * 1024 * 1024) {
            alert('File is larger than 5MB.');
            this.value = '';
            return;
        }
        const fd = new FormData();
        fd.append('csrf_token', csrfToken);
        fd.append('qr_image', file);

        const btn = document.querySelector('label[for="qrFileInput"]');
        const original = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Uploading...';

        fetch('upload_gcash_qr.php', {
            method: 'POST', body: fd, credentials: 'same-origin'
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                alert('Upload failed: ' + (data.message || 'Unknown error'));
                btn.innerHTML = original;
            }
        })
        .catch(err => {
            alert('Upload failed: ' + err.message);
            btn.innerHTML = original;
        });
    });

    // Delete QR
    document.getElementById('deleteQrBtn')?.addEventListener('click', function () {
        if (!confirm('Remove the GCash QR code? Members will not see a QR until a new one is uploaded.')) {
            return;
        }
        const fd = new FormData();
        fd.append('csrf_token', csrfToken);
        fetch('delete_gcash_qr.php', {
            method: 'POST', body: fd, credentials: 'same-origin'
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                alert('Failed: ' + (data.message || 'Unknown error'));
            }
        });
    });

    // Save account info
    document.getElementById('saveGcashInfoBtn')?.addEventListener('click', function () {
        const fd = new FormData();
        fd.append('csrf_token', csrfToken);
        fd.append('gcash_mobile',  document.getElementById('gcashMobile').value);
        fd.append('gcash_name',    document.getElementById('gcashName').value);
        fd.append('gcash_user_id', document.getElementById('gcashUserId').value);

        const btn = this;
        const original = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Saving...';

        fetch('save_gcash_info.php', {
            method: 'POST', body: fd, credentials: 'same-origin'
        })
        .then(r => r.json())
        .then(data => {
            alert(data.message);
            if (data.success) {
                location.reload();
            } else {
                btn.disabled = false;
                btn.innerHTML = original;
            }
        })
        .catch(err => {
            alert('Failed: ' + err.message);
            btn.disabled = false;
            btn.innerHTML = original;
        });
    });
})();
</script>
</body>
</html>