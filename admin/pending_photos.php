<?php
// admin/pending_photos.php - Member Photo Approval Management
require_once '../includes/config.php';
require_once '../includes/db_connection.php';
require_once '../includes/security.php';
require_once '../includes/auth.php';
require_once '../includes/user_functions.php';

$auth->requireLogin();
$auth->requireRole('admin');
$current_user = $auth->getCurrentUser();

$db = Database::getInstance();

// Get counts
$user_pending_count = $db->getSingle("SELECT COUNT(*) as cnt FROM pending_users WHERE status = 'pending'")['cnt'] ?? 0;
$photo_pending_count = $db->getSingle("SELECT COUNT(*) as cnt FROM member_photo_requests WHERE status = 'pending'")['cnt'] ?? 0;
$total_pending = $user_pending_count + $photo_pending_count;

$message = '';
$error = '';

// Handle photo request actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token.';
    } else {
        $action = $_POST['action'] ?? '';
        $request_id = (int)($_POST['request_id'] ?? 0);
        
        if ($action === 'approve_photo') {
            $request = $db->getSingle(
                "SELECT * FROM member_photo_requests WHERE request_id = ? AND status = 'pending'",
                [$request_id], 'i'
            );
            
            if (!$request) {
                $error = 'Photo request not found or already processed.';
            } else {
                $db->getConnection()->begin_transaction();
                
                try {
                    // Get member details
                    $member = $db->getSingle(
                        "SELECT * FROM members WHERE member_code = ?",
                        [$request['member_code']], 's'
                    );
                    
                    if (!$member) {
                        throw new Exception('Member not found.');
                    }
                    
                    // Delete old photo if exists
                    if (!empty($member['profile_photo']) && file_exists('../' . $member['profile_photo'])) {
                        unlink('../' . $member['profile_photo']);
                    }
                    
                    // Update member with new photo
                    $result = $db->execute(
                        "UPDATE members SET profile_photo = ?, updated_at = NOW() WHERE member_code = ?",
                        [$request['photo_path'], $request['member_code']],
                        'ss'
                    );
                    
                    if (!$result) {
                        throw new Exception('Failed to update member photo.');
                    }
                    
                    // Update request status
                    $db->execute(
                        "UPDATE member_photo_requests SET status = 'approved', reviewed_by = ?, reviewed_at = NOW() WHERE request_id = ?",
                        [$current_user['user_id'], $request_id],
                        'ii'
                    );
                    
                    // Create notification for member
                    if (!empty($member['user_id'])) {
                        createNotification(
                            $db,
                            $member['user_id'],
                            "Profile Photo Approved",
                            "Your profile photo change request has been approved! Your new photo is now visible on your profile.",
                            'account',
                            '../user/profile.php',
                            'user-check'
                        );
                    }
                    
                    $db->getConnection()->commit();
                    $message = "Photo approved for member: {$member['first_name']} {$member['last_name']}";
                    Security::logEvent('PHOTO_APPROVED', "Approved photo for member: {$request['member_code']}");
                    
                } catch (Exception $e) {
                    $db->getConnection()->rollback();
                    $error = 'Failed to approve photo: ' . $e->getMessage();
                    error_log('Photo approval error: ' . $e->getMessage());
                }
            }
            
        } elseif ($action === 'reject_photo') {
            $request = $db->getSingle(
                "SELECT * FROM member_photo_requests WHERE request_id = ? AND status = 'pending'",
                [$request_id], 'i'
            );
            
            if (!$request) {
                $error = 'Photo request not found or already processed.';
            } else {
                $rejection_reason = Security::sanitize($_POST['rejection_reason'] ?? 'No reason provided');
                
                $db->getConnection()->begin_transaction();
                
                try {
                    // Get member details
                    $member = $db->getSingle(
                        "SELECT * FROM members WHERE member_code = ?",
                        [$request['member_code']], 's'
                    );
                    
                    // Delete the uploaded photo file
                    if (file_exists('../' . $request['photo_path'])) {
                        unlink('../' . $request['photo_path']);
                    }
                    
                    // Update request status
                    $db->execute(
                        "UPDATE member_photo_requests SET status = 'rejected', reviewed_by = ?, reviewed_at = NOW(), admin_notes = ? WHERE request_id = ?",
                        [$current_user['user_id'], $rejection_reason, $request_id],
                        'isi'
                    );
                    
                    // Create notification for member with rejection reason
                    if (!empty($member['user_id'])) {
                        createNotification(
                            $db,
                            $member['user_id'],
                            "Profile Photo Update Rejected",
                            "Your profile photo change request was rejected. Reason: " . $rejection_reason . " Please upload a new photo following the guidelines.",
                            'account',
                            '../user/profile.php',
                            'times-circle'
                        );
                    }
                    
                    $db->getConnection()->commit();
                    $message = "Photo request rejected for member: {$member['first_name']} {$member['last_name']}";
                    Security::logEvent('PHOTO_REJECTED', "Rejected photo for member: {$request['member_code']}");
                    
                } catch (Exception $e) {
                    $db->getConnection()->rollback();
                    $error = 'Failed to reject photo: ' . $e->getMessage();
                    error_log('Photo rejection error: ' . $e->getMessage());
                }
            }
        }
    }
}

// Fetch pending photo requests with member details
$pending_photos = $db->getAll("
    SELECT pr.*, 
           m.first_name, m.last_name, m.middle_name, m.member_code,
           m.chapter, m.group_name, m.barangay, m.city, m.province, m.street,
           m.contact_number, m.email, m.birth_date, m.age, m.gender, m.civil_status,
           m.date_joined, m.status as member_status,
           m.profile_photo as current_photo
    FROM member_photo_requests pr
    JOIN members m ON pr.member_code = m.member_code
    WHERE pr.status = 'pending'
    ORDER BY pr.requested_at DESC
");

$csrf_token = Security::generateCSRFToken();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pending Photo Approvals - Harana Financial System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * {
            box-sizing: border-box;
        }
        body {
            background: #f4f7fc;
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
        
        /* Sidebar Styles */
        #sidebar-wrapper {
            background: #375a7f;
            color: #fff;
            width: 250px;
            height: 100vh;
            overflow-y: auto;
            transition: width 0.3s ease;
            box-shadow: 4px 0 10px rgba(0,0,0,0.1);
            white-space: nowrap;
        }
        
        #sidebar-wrapper.collapsed { width: 70px; }
        #sidebar-wrapper.collapsed .sidebar-heading span { display: none; }
        #sidebar-wrapper.collapsed .list-group-item span { display: none; }
        #sidebar-wrapper.collapsed .list-group-item i {
            margin-right: 0;
            width: 100%;
            text-align: center;
            font-size: 1.2rem;
        }
        #sidebar-wrapper.collapsed .list-group-item { padding: 15px 0; text-align: center; }
        #sidebar-wrapper.collapsed .badge { display: none; }
        #sidebar-wrapper.collapsed .sidebar-heading img { display: none; }

        #sidebar-wrapper .sidebar-heading {
            padding: 1.2rem 1rem;
            font-size: 1.4rem;
            font-weight: 600;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            background: #375a7f;
            color: white;
            text-align: left;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        #sidebar-wrapper.collapsed .sidebar-heading { justify-content: center; padding: 1.2rem 0; }
        #sidebar-wrapper .sidebar-heading img { height: 30px; width: auto; margin-right: 10px; }
        .menu-toggle { background: transparent; border: none; font-size: 1.5rem; color: white; cursor: pointer; padding: 0 10px; }
        .header-logo { height: 30px; width: auto; margin-right: 10px; display: none; }
        #sidebar-wrapper.collapsed ~ #page-content-wrapper .header-logo { display: inline-block; }

        #sidebar-wrapper .list-group-item {
            background: transparent;
            border: none;
            color: rgba(255,255,255,0.9);
            padding: 0.8rem 1.2rem;
            font-weight: 500;
            transition: all 0.2s;
            font-size: 0.95rem;
            text-align: left;
        }

        #sidebar-wrapper .list-group-item:hover,
        #sidebar-wrapper .list-group-item.active {
            background: rgba(255,255,255,0.15);
            color: #fff;
            border-left: 4px solid #fff;
        }

        #sidebar-wrapper .list-group-item i { width: 24px; margin-right: 10px; }
        
        #page-content-wrapper {
            flex: 1;
            background: #f4f7fc;
            height: 100vh;
            overflow-y: auto;
            padding: 0;
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
        
        .navbar-brand { font-size: 1.2rem; font-weight: 500; color: #375a7f !important; }
        .navbar-right { display: flex; align-items: center; gap: 20px; }
        
        .card {
            border: none;
            border-radius: 10px;
            box-shadow: 0 0 20px rgba(0,0,0,.1);
        }
        
        .card-header {
            background: linear-gradient(135deg, #375a7f, #2c4a6b);
            color: white;
            border-radius: 10px 10px 0 0 !important;
        }
        
        .table th {
            font-weight: 600;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #6c757d;
        }
        
        .table td {
            font-size: 14px;
            vertical-align: middle;
        }
        
        .photo-thumb {
            width: 60px;
            height: 60px;
            object-fit: cover;
            border-radius: 8px;
            cursor: pointer;
            border: 2px solid #dee2e6;
            transition: transform 0.2s;
        }
        
        .photo-thumb:hover {
            transform: scale(1.05);
            border-color: #375a7f;
        }
        
        .photo-preview-modal .modal-dialog {
            max-width: 800px;
        }
        
        .member-detail-section {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
        }
        
        .detail-label {
            font-size: 0.7rem;
            text-transform: uppercase;
            color: #6c757d;
            font-weight: 600;
        }
        
        .detail-value {
            font-size: 0.9rem;
            color: #2c3e50;
        }
        
        .badge-count {
            position: absolute !important;
            top: 50% !important;
            right: 10px !important;
            left: auto !important;
            transform: translateY(-50%) !important;
            font-size: 0.7rem !important;
            padding: 3px 6px !important;
            border-radius: 10px !important;
            min-width: 20px !important;
            text-align: center !important;
            z-index: 100 !important;
        }

        #sidebar-wrapper.collapsed .badge-count { display: none !important; }
        
        .dropdown-nav {
            margin-bottom: 20px;
        }
        
        .dropdown-nav .btn {
            background: white;
            border: 1px solid #dee2e6;
            color: #375a7f;
            padding: 10px 20px;
        }
        
        .rejection-reason {
            margin-top: 10px;
            display: none;
        }
        
        @media (max-width: 768px) {
            .photo-thumb {
                width: 40px;
                height: 40px;
            }
        }
    </style>
</head>
<body>
    <div class="d-flex" id="wrapper">
        <!-- Sidebar -->
        <div id="sidebar-wrapper">
            <div class="sidebar-heading">
                <img src="../assets/images/harana-logo.png" alt="Harana" onerror="this.src=''; this.onerror=null; this.innerHTML='Harana';">
                <span>Harana</span>
                <button class="menu-toggle" id="menuToggle">
                    <i class="fas fa-bars"></i>
                </button>
            </div>
            <div class="list-group list-group-flush mt-2">
                <a href="dashboard.php" class="list-group-item"><i class="fas fa-tachometer-alt"></i><span>Dashboard</span></a>
                <a href="members.php" class="list-group-item"><i class="fas fa-users"></i><span>Members</span></a>
                <a href="council.php" class="list-group-item"><i class="fas fa-user-tie"></i><span>Council</span></a>
                <a href="payments.php" class="list-group-item"><i class="fas fa-credit-card"></i><span>Payments</span></a>
                <a href="reports.php" class="list-group-item"><i class="fas fa-chart-bar"></i><span>Reports</span></a>
                <?php if ($current_user['role'] === 'admin'): ?>
                <a href="pending_users.php" class="list-group-item active position-relative">
                    <i class="fas fa-user-clock"></i><span>Pending</span>
                    <?php if ($total_pending > 0): ?>
                        <span class="badge bg-danger position-absolute top-0 start-100 translate-middle badge-count"><?php echo $total_pending; ?></span>
                    <?php endif; ?>
                </a>
                <?php endif; ?>
                <a href="announcements.php" class="list-group-item"><i class="fas fa-bullhorn"></i><span>Announcements</span></a>
                <a href="settings.php" class="list-group-item"><i class="fas fa-cog"></i><span>Settings</span></a>
                <a href="../logout.php" class="list-group-item" onclick="return confirm('Are you sure?');"><i class="fas fa-sign-out-alt"></i><span>Logout</span></a>
            </div>
        </div>

        <!-- Main Content -->
        <div class="flex-grow-1">
            <nav class="navbar navbar-light bg-light px-4 py-3">
                <span class="navbar-brand">Pending Photo Approvals</span>
                <div class="ms-auto">
                    <span><i class="fas fa-user-circle me-2"></i><?php echo htmlspecialchars($current_user['full_name'] ?? $current_user['username']); ?></span>
                </div>
            </nav>

            <div class="container-fluid p-4">
                <!-- Navigation Dropdown -->
                <div class="dropdown-nav">
                    <div class="btn-group">
                        <a href="pending_users.php" class="btn btn-outline-primary">
                            <i class="fas fa-user-plus me-1"></i> User Registrations
                            <?php if ($user_pending_count > 0): ?>
                                <span class="badge bg-danger ms-1"><?php echo $user_pending_count; ?></span>
                            <?php endif; ?>
                        </a>
                        <a href="pending_photos.php" class="btn btn-primary active">
                            <i class="fas fa-camera me-1"></i> Photo Requests
                            <?php if ($photo_pending_count > 0): ?>
                                <span class="badge bg-light text-dark ms-1"><?php echo $photo_pending_count; ?></span>
                            <?php endif; ?>
                        </a>
                    </div>
                </div>

                <!-- Toast Notification Container -->
                <div class="toast-container position-fixed top-0 end-0 p-3">
                    <div id="successToast" class="toast align-items-center text-white bg-success border-0" role="alert">
                        <div class="d-flex">
                            <div class="toast-body">
                                <i class="fas fa-check-circle me-2"></i>
                                <span id="successMessage"></span>
                            </div>
                            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
                        </div>
                    </div>
                    <div id="errorToast" class="toast align-items-center text-white bg-danger border-0" role="alert">
                        <div class="d-flex">
                            <div class="toast-body">
                                <i class="fas fa-exclamation-circle me-2"></i>
                                <span id="errorMessage"></span>
                            </div>
                            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
                        </div>
                    </div>
                </div>

                <?php if ($message): ?>
                    <div class="alert alert-success alert-dismissible fade show"><?php echo $message; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
                <?php endif; ?>
                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show"><?php echo $error; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
                <?php endif; ?>

                <div class="card">
                    <div class="card-header">
                        <h5><i class="fas fa-camera me-2"></i>Member Photo Change Requests</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($pending_photos)): ?>
                            <p class="text-muted text-center py-4">No pending photo change requests.</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Member Info</th>
                                            <th>Current Photo</th>
                                            <th>New Photo</th>
                                            <th>Request Date</th>
                                            <th>Actions</th>
                                        </thead>
                                    <tbody>
                                        <?php foreach ($pending_photos as $photo): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($photo['first_name'] . ' ' . $photo['last_name']); ?></strong><br>
                                                <small class="text-muted">Code: <?php echo htmlspecialchars($photo['member_code']); ?></small><br>
                                                <small class="text-muted">Chapter: <?php echo htmlspecialchars($photo['chapter'] ?? 'N/A'); ?></small><br>
                                                <small class="text-muted">Group: <?php echo htmlspecialchars($photo['group_name'] ?? 'N/A'); ?></small>
                                            </td>
                                            <td>
                                                <?php if (!empty($photo['current_photo'])): ?>
                                                    <img src="../<?php echo htmlspecialchars($photo['current_photo']); ?>" 
                                                         class="photo-thumb" 
                                                         onclick="viewPhotoDetails(<?php echo htmlspecialchars(json_encode($photo)); ?>, 'current')"
                                                         style="cursor: pointer;">
                                                <?php else: ?>
                                                    <div class="photo-thumb bg-light d-flex align-items-center justify-content-center" style="cursor: pointer;" onclick="viewPhotoDetails(<?php echo htmlspecialchars(json_encode($photo)); ?>, 'current')">
                                                        <i class="fas fa-user-circle fa-2x text-secondary"></i>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <img src="../<?php echo htmlspecialchars($photo['photo_path']); ?>" 
                                                     class="photo-thumb" 
                                                     onclick="viewPhotoDetails(<?php echo htmlspecialchars(json_encode($photo)); ?>, 'new')"
                                                     style="cursor: pointer;">
                                            </td>
                                            <td>
                                                <?php echo date('M d, Y h:i A', strtotime($photo['requested_at'])); ?>
                                            </td>
                                            <td>
                                                <form method="POST" style="display: inline-block;" onsubmit="return confirmApprove(this)">
                                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                                    <input type="hidden" name="action" value="approve_photo">
                                                    <input type="hidden" name="request_id" value="<?php echo $photo['request_id']; ?>">
                                                    <button type="submit" class="btn btn-sm btn-success">
                                                        <i class="fas fa-check"></i> Approve
                                                    </button>
                                                </form>
                                                <button type="button" class="btn btn-sm btn-danger" onclick="showRejectModal(<?php echo $photo['request_id']; ?>, '<?php echo htmlspecialchars($photo['first_name'] . ' ' . $photo['last_name']); ?>')">
                                                    <i class="fas fa-times"></i> Reject
                                                </button>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Member Details Modal -->
    <div class="modal fade" id="memberDetailsModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title"><i class="fas fa-file-alt me-2"></i>Member Information</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="memberDetailsContent">
                    <div class="text-center p-5">
                        <div class="spinner-border text-info" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Rejection Modal -->
    <div class="modal fade" id="rejectModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="fas fa-times-circle me-2"></i>Reject Photo Request</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="rejectForm">
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="action" value="reject_photo">
                        <input type="hidden" name="request_id" id="reject_request_id">
                        
                        <p>Reject photo request for: <strong id="reject_member_name"></strong></p>
                        
                        <div class="mb-3">
                            <label class="form-label">Rejection Reason <span class="text-danger">*</span></label>
                            <select class="form-select" name="rejection_reason" id="rejection_reason" required onchange="toggleOtherReason()">
                                <option value="">Select a reason</option>
                                <option value="Photo is too blurry or low quality">Photo is too blurry or low quality</option>
                                <option value="Photo does not show the member clearly">Photo does not show the member clearly</option>
                                <option value="Photo contains inappropriate content">Photo contains inappropriate content</option>
                                <option value="Photo is not a valid identification photo">Photo is not a valid identification photo</option>
                                <option value="Photo size or format is incorrect">Photo size or format is incorrect</option>
                                <option value="Other">Other (please specify)</option>
                            </select>
                        </div>
                        
                        <div class="mb-3" id="other_reason_container" style="display: none;">
                            <label class="form-label">Please specify reason</label>
                            <input type="text" class="form-control" name="other_reason" id="other_reason" placeholder="Enter rejection reason">
                        </div>
                        
                        <div class="alert alert-warning">
                            <i class="fas fa-info-circle me-2"></i>
                            The member will be notified of this rejection with the reason provided.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">Reject Request</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        let currentPhotoData = null;
        let currentPhotoType = null;
        
        // View photo and member details
        function viewPhotoDetails(photoData, type) {
            currentPhotoData = photoData;
            currentPhotoType = type;
            
            const modal = new bootstrap.Modal(document.getElementById('memberDetailsModal'));
            
            // Build member details HTML
            const fullName = photoData.first_name + ' ' + photoData.last_name;
            const photoUrl = type === 'current' 
                ? (photoData.current_photo ? '../' + photoData.current_photo : null)
                : '../' + photoData.photo_path;
            
            const address = [
                photoData.street ? photoData.street : '',
                photoData.barangay ? 'Brgy. ' + photoData.barangay : '',
                photoData.city ? photoData.city : '',
                photoData.province ? photoData.province : ''
            ].filter(Boolean).join(', ');
            
            const birthDate = photoData.birth_date && photoData.birth_date !== '0000-00-00' 
                ? new Date(photoData.birth_date).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' })
                : 'Not specified';
            
            let html = `
                <div class="row">
                    <div class="col-md-5 text-center mb-4">
                        <div class="border rounded p-3 bg-light">
                            <h6 class="mb-3">${type === 'current' ? 'Current Photo' : 'New Photo Request'}</h6>
                            ${photoUrl ? 
                                `<img src="${photoUrl}" class="img-fluid rounded" style="max-height: 300px; object-fit: contain;">` :
                                `<div class="py-5"><i class="fas fa-user-circle fa-5x text-secondary"></i><p class="mt-2">No current photo</p></div>`
                            }
                        </div>
                    </div>
                    <div class="col-md-7">
                        <div class="member-detail-section">
                            <h6 class="mb-3"><i class="fas fa-user me-2"></i>Personal Information</h6>
                            <div class="row">
                                <div class="col-sm-6 mb-2">
                                    <div class="detail-label">Full Name</div>
                                    <div class="detail-value">${fullName}</div>
                                </div>
                                <div class="col-sm-6 mb-2">
                                    <div class="detail-label">Member Code</div>
                                    <div class="detail-value">${photoData.member_code || 'N/A'}</div>
                                </div>
                                <div class="col-sm-6 mb-2">
                                    <div class="detail-label">Gender</div>
                                    <div class="detail-value">${photoData.gender || 'Not specified'}</div>
                                </div>
                                <div class="col-sm-6 mb-2">
                                    <div class="detail-label">Birth Date</div>
                                    <div class="detail-value">${birthDate}</div>
                                </div>
                                <div class="col-sm-6 mb-2">
                                    <div class="detail-label">Age</div>
                                    <div class="detail-value">${photoData.age || 'N/A'}</div>
                                </div>
                                <div class="col-sm-6 mb-2">
                                    <div class="detail-label">Civil Status</div>
                                    <div class="detail-value">${photoData.civil_status || 'Not specified'}</div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="member-detail-section">
                            <h6 class="mb-3"><i class="fas fa-map-marker-alt me-2"></i>Address</h6>
                            <div class="detail-value">${address || 'Not specified'}</div>
                        </div>
                        
                        <div class="member-detail-section">
                            <h6 class="mb-3"><i class="fas fa-phone me-2"></i>Contact</h6>
                            <div class="detail-value">${photoData.contact_number || 'Not specified'}</div>
                            <div class="detail-value mt-1">${photoData.email || 'Not specified'}</div>
                        </div>
                        
                        <div class="member-detail-section">
                            <h6 class="mb-3"><i class="fas fa-users me-2"></i>Chapter Information</h6>
                            <div class="row">
                                <div class="col-sm-6 mb-2">
                                    <div class="detail-label">Chapter</div>
                                    <div class="detail-value">${photoData.chapter || 'Not assigned'}</div>
                                </div>
                                <div class="col-sm-6 mb-2">
                                    <div class="detail-label">Group</div>
                                    <div class="detail-value">${photoData.group_name || 'Not assigned'}</div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="member-detail-section">
                            <h6 class="mb-3"><i class="fas fa-calendar-alt me-2"></i>Membership</h6>
                            <div class="row">
                                <div class="col-sm-6 mb-2">
                                    <div class="detail-label">Date Joined</div>
                                    <div class="detail-value">${photoData.date_joined ? new Date(photoData.date_joined).toLocaleDateString() : 'N/A'}</div>
                                </div>
                                <div class="col-sm-6 mb-2">
                                    <div class="detail-label">Status</div>
                                    <div class="detail-value"><span class="badge bg-${photoData.member_status === 'active' ? 'success' : 'warning'}">${photoData.member_status || 'active'}</span></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            
            document.getElementById('memberDetailsContent').innerHTML = html;
            modal.show();
        }
        
        // Show reject modal
        function showRejectModal(requestId, memberName) {
            document.getElementById('reject_request_id').value = requestId;
            document.getElementById('reject_member_name').textContent = memberName;
            document.getElementById('rejection_reason').value = '';
            document.getElementById('other_reason_container').style.display = 'none';
            document.getElementById('other_reason').value = '';
            
            new bootstrap.Modal(document.getElementById('rejectModal')).show();
        }
        
        // Toggle other reason input
        function toggleOtherReason() {
            const reason = document.getElementById('rejection_reason').value;
            const otherContainer = document.getElementById('other_reason_container');
            
            if (reason === 'Other') {
                otherContainer.style.display = 'block';
                document.getElementById('other_reason').required = true;
            } else {
                otherContainer.style.display = 'none';
                document.getElementById('other_reason').required = false;
            }
        }
        
        // Confirm approve
        function confirmApprove(form) {
            return confirm('Approve this photo request? The member\'s profile photo will be updated.');
        }
        
        // Handle reject form submission
        document.getElementById('rejectForm').addEventListener('submit', function(e) {
            const reason = document.getElementById('rejection_reason').value;
            const otherReason = document.getElementById('other_reason').value;
            
            if (!reason) {
                e.preventDefault();
                alert('Please select a rejection reason.');
                return false;
            }
            
            if (reason === 'Other' && !otherReason.trim()) {
                e.preventDefault();
                alert('Please specify the rejection reason.');
                return false;
            }
            
            // Set the final reason
            let finalReason = reason;
            if (reason === 'Other') {
                finalReason = otherReason;
            }
            
            // Update the input value
            const hiddenReason = document.createElement('input');
            hiddenReason.type = 'hidden';
            hiddenReason.name = 'rejection_reason';
            hiddenReason.value = finalReason;
            this.appendChild(hiddenReason);
            
            return confirm('Reject this photo request? The member will be notified.');
        });
        
        // Sidebar Toggle
        const menuToggle = document.getElementById('menuToggle');
        const sidebar = document.getElementById('sidebar-wrapper');
        const headerLogo = document.getElementById('headerLogo');
        
        const sidebarCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';
        if (sidebarCollapsed) sidebar.classList.add('collapsed');
        
        menuToggle.addEventListener('click', function() {
            sidebar.classList.toggle('collapsed');
            localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
        });
        
        const sidebarLogo = document.querySelector('.sidebar-heading img');
        if (sidebarLogo) {
            sidebarLogo.onerror = function() {
                this.style.display = 'none';
                this.nextSibling.textContent = 'Harana';
            };
        }
        
        if (headerLogo) {
            headerLogo.onerror = function() {
                this.style.display = 'none';
            };
        }
        
        // Show toasts
        <?php if ($message): ?>
        var toast = new bootstrap.Toast(document.getElementById('successToast'));
        document.getElementById('successMessage').textContent = '<?php echo addslashes($message); ?>';
        toast.show();
        <?php endif; ?>
        
        <?php if ($error): ?>
        var toast = new bootstrap.Toast(document.getElementById('errorToast'));
        document.getElementById('errorMessage').textContent = '<?php echo addslashes($error); ?>';
        toast.show();
        <?php endif; ?>
    </script>
</body>
</html>