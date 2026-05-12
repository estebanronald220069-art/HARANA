<?php
// admin/council.php - Council Members Management with Profile Pictures
require_once '../includes/config.php';
require_once '../includes/db_connection.php';
require_once '../includes/security.php';
require_once '../includes/auth.php';

$auth->requireLogin();
$current_user = $auth->getCurrentUser();

// Optional: restrict to admin only
// if ($current_user['role'] !== 'admin') {
//     header('Location: dashboard.php?error=access_denied');
//     exit();
// }

$db = Database::getInstance();
$message = '';
$error = '';

// Check for success message in URL
if (isset($_GET['success'])) {
    if ($_GET['success'] == 'added') {
        $message = 'Council member added successfully.';
    } elseif ($_GET['success'] == 'updated') {
        $message = 'Council member updated successfully.';
    } elseif ($_GET['success'] == 'deleted') {
        $message = 'Council member deactivated successfully.';
    } elseif ($_GET['success'] == 'photo_uploaded') {
        $message = 'Profile picture uploaded successfully.';
    } elseif ($_GET['success'] == 'photo_deleted') {
        $message = 'Profile picture deleted successfully.';
    }
}

// Get pending users count (for sidebar badge)
$pending_count = 0;
if ($current_user['role'] === 'admin') {
    $pending_count = $db->getSingle("SELECT COUNT(*) as cnt FROM pending_users WHERE status = 'pending'")['cnt'] ?? 0;
}

// Pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// Search
$search = isset($_GET['search']) ? Security::sanitize($_GET['search']) : '';
$search_condition = '';
$search_params = [];
$types = '';

if (!empty($search)) {
    $search_condition = "WHERE (full_name LIKE ? OR position LIKE ? OR email LIKE ?) AND status = 'active'";
    $search_term = "%$search%";
    $search_params = [$search_term, $search_term, $search_term];
    $types = 'sss';
} else {
    $search_condition = "WHERE status = 'active'";
}

// Total records
$total_sql = "SELECT COUNT(*) as total FROM council $search_condition";
$total_result = $db->getSingle($total_sql, $search_params, $types);
$total_records = $total_result['total'] ?? 0;
$total_pages = ceil($total_records / $limit);

// Fetch council members
$sql = "SELECT * FROM council $search_condition ORDER BY created_at DESC LIMIT ? OFFSET ?";
$params = array_merge($search_params, [$limit, $offset]);
$types_param = $types . 'ii';
$council_members = $db->getAll($sql, $params, $types_param);

// Define position options
$positions = [
    'CEO/President' => 'CEO/President',
    'COO/Vice President' => 'COO/Vice President',
    'CFO/Treasurer' => 'CFO/Treasurer',
    'Book Keeper' => 'Book Keeper',
    'Corporate Secretary' => 'Corporate Secretary',
    'Supply Officer' => 'Supply Officer',
    'Internal Auditor' => 'Internal Auditor',
    'Monitoring Officer I' => 'Monitoring Officer I',
    'Monitoring Officer II' => 'Monitoring Officer II',
    'Liaison/Office Secretary' => 'Liaison/Office Secretary',
    'Encoder' => 'Encoder',
    'Over-all Adviser' => 'Over-all Adviser'
];

// Handle POST requests (Add, Edit, Delete, Toggle Status)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please refresh the page.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'add') {
            $last_name = Security::sanitize($_POST['last_name'] ?? '');
            $first_name = Security::sanitize($_POST['first_name'] ?? '');
            $middle_name = Security::sanitize($_POST['middle_name'] ?? '');
            $full_name = Security::sanitize($_POST['full_name'] ?? '');
            $position = Security::sanitize($_POST['position'] ?? '');
            $contact_number = Security::sanitize($_POST['contact_number'] ?? '');
            $email = Security::sanitize($_POST['email'] ?? '');
            $term_start = $_POST['term_start'] ?? null;
            $term_end = $_POST['term_end'] ?? null;

            if (empty($last_name) || empty($first_name) || empty($position)) {
                $error = 'Last name, first name, and position are required.';
            } else {
                $insert_sql = "INSERT INTO council (last_name, first_name, middle_name, full_name, position, contact_number, email, term_start, term_end, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $insert_params = [$last_name, $first_name, $middle_name, $full_name, $position, $contact_number, $email, $term_start, $term_end, $current_user['user_id']];
                $insert_types = 'sssssssssi';

                $result = $db->execute($insert_sql, $insert_params, $insert_types);
                if ($result) {
                    Security::logEvent('COUNCIL_ADD', "Added council member: $full_name");
                    header('Location: council.php?success=added');
                    exit();
                } else {
                    $error = 'Failed to add council member.';
                }
            }
        } elseif ($action === 'edit') {
            $council_id = (int)$_POST['council_id'];
            $last_name = Security::sanitize($_POST['last_name'] ?? '');
            $first_name = Security::sanitize($_POST['first_name'] ?? '');
            $middle_name = Security::sanitize($_POST['middle_name'] ?? '');
            $full_name = Security::sanitize($_POST['full_name'] ?? '');
            $position = Security::sanitize($_POST['position'] ?? '');
            $contact_number = Security::sanitize($_POST['contact_number'] ?? '');
            $email = Security::sanitize($_POST['email'] ?? '');
            $term_start = $_POST['term_start'] ?? null;
            $term_end = $_POST['term_end'] ?? null;
            $status = Security::sanitize($_POST['status'] ?? 'active');

            if (empty($last_name) || empty($first_name) || empty($position)) {
                $error = 'Last name, first name, and position are required.';
            } else {
                $update_sql = "UPDATE council SET last_name=?, first_name=?, middle_name=?, full_name=?, position=?, contact_number=?, email=?, term_start=?, term_end=?, status=?, updated_by=? WHERE council_id=?";
                $update_params = [$last_name, $first_name, $middle_name, $full_name, $position, $contact_number, $email, $term_start, $term_end, $status, $current_user['user_id'], $council_id];
                $update_types = 'ssssssssssii';

                $result = $db->execute($update_sql, $update_params, $update_types);
                if ($result !== false) {
                    Security::logEvent('COUNCIL_EDIT', "Edited council member ID: $council_id");
                    header('Location: council.php?success=updated');
                    exit();
                } else {
                    $error = 'Failed to update council member.';
                }
            }
        } elseif ($action === 'delete') {
            $council_id = (int)$_POST['council_id'];
            
            // Get photo path before soft delete
            $member = $db->getSingle("SELECT photo FROM council WHERE council_id = ?", [$council_id], 'i');
            
            // Soft delete
            $result = $db->execute("UPDATE council SET status='inactive', updated_by=? WHERE council_id=?", [$current_user['user_id'], $council_id], 'ii');
            
            if ($result) {
                // Delete photo file if exists
                if ($member && $member['photo'] && file_exists('../' . $member['photo'])) {
                    unlink('../' . $member['photo']);
                }
                
                Security::logEvent('COUNCIL_DELETE', "Deactivated council member ID: $council_id");
                header('Location: council.php?success=deleted');
                exit();
            } else {
                $error = 'Failed to deactivate council member.';
            }
        } elseif ($action === 'toggle_status') {
            $council_id = (int)$_POST['council_id'];
            $new_status = Security::sanitize($_POST['new_status'] ?? '');
            
            if (in_array($new_status, ['active', 'inactive'])) {
                $result = $db->execute("UPDATE council SET status=?, updated_by=? WHERE council_id=?", [$new_status, $current_user['user_id'], $council_id], 'sii');
                if ($result) {
                    $message = 'Council member status updated successfully.';
                    Security::logEvent('COUNCIL_STATUS', "Changed status of council member ID: $council_id to $new_status");
                    
                    // Return JSON response for AJAX requests
                    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
                        header('Content-Type: application/json');
                        echo json_encode(['success' => true, 'message' => $message, 'new_status' => $new_status]);
                        exit();
                    }
                } else {
                    $error = 'Failed to update council member status.';
                    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
                        header('Content-Type: application/json');
                        echo json_encode(['success' => false, 'error' => $error]);
                        exit();
                    }
                }
            }
        }
    }
}

$csrf_token = Security::generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Council Members - <?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .status-badge {
            cursor: pointer;
            transition: opacity 0.2s;
        }
        .status-badge:hover {
            opacity: 0.8;
        }
        .full-name-display {
            font-weight: 500;
            cursor: pointer;
            color: #0d6efd;
        }
        .full-name-display:hover {
            text-decoration: underline;
        }
        .member-info-card {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .info-label {
            font-weight: 600;
            color: #6c757d;
            font-size: 0.9rem;
            margin-bottom: 5px;
        }
        .info-value {
            font-size: 1.1rem;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid #dee2e6;
        }
        .info-value:last-child {
            border-bottom: none;
        }
        .profile-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            border-radius: 10px;
            margin-bottom: 20px;
            position: relative;
        }
        .profile-image-container {
            position: relative;
            width: 150px;
            height: 150px;
            margin: 0 auto 15px;
            border-radius: 50%;
            overflow: hidden;
            border: 4px solid white;
            box-shadow: 0 4px 10px rgba(0,0,0,0.2);
        }
        .profile-image {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .profile-image-placeholder {
            width: 100%;
            height: 100%;
            background: rgba(255,255,255,0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 4rem;
        }
        .profile-image-actions {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            background: rgba(0,0,0,0.7);
            color: white;
            padding: 5px;
            text-align: center;
            transform: translateY(100%);
            transition: transform 0.3s ease;
        }
        .profile-image-container:hover .profile-image-actions {
            transform: translateY(0);
        }
        .profile-image-actions .btn-link {
            color: white;
            text-decoration: none;
            font-size: 0.9rem;
        }
        .profile-image-actions .btn-link:hover {
            color: #ddd;
        }
        .profile-icon {
            font-size: 4rem;
            margin-bottom: 15px;
        }
        .error-details {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
            padding: 15px;
            border-radius: 5px;
            margin: 10px 0;
        }
        .member-thumbnail {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
            margin-right: 10px;
        }
        .member-name-with-photo {
            display: flex;
            align-items: center;
        }
        .photo-upload-progress {
            margin-top: 10px;
            display: none;
        }
        .photo-preview {
            max-width: 200px;
            max-height: 200px;
            margin: 10px auto;
            border-radius: 10px;
            display: none;
        }
    </style>
</head>
<body>
    <div class="d-flex" id="wrapper">
        <!-- Sidebar -->
        <div class="bg-primary text-white" style="width: 250px; min-height: 100vh;">
            <div class="p-3">
                <h4 class="text-center mb-4"><i class="fas fa-hand-holding-heart me-2"></i>Harana</h4>
                <hr>
                <div class="list-group list-group-flush">
                    <a href="dashboard.php" class="list-group-item list-group-item-action bg-transparent text-white">
                        <i class="fas fa-tachometer-alt me-2"></i>Dashboard
                    </a>
                    <a href="members.php" class="list-group-item list-group-item-action bg-transparent text-white">
                        <i class="fas fa-users me-2"></i>Members
                    </a>
                    <a href="council.php" class="list-group-item list-group-item-action bg-transparent text-white active">
                        <i class="fas fa-user-tie me-2"></i>Council
                    </a>
                    <a href="payments.php" class="list-group-item list-group-item-action bg-transparent text-white">
                        <i class="fas fa-credit-card me-2"></i>Payments
                    </a>
                    <a href="reports.php" class="list-group-item list-group-item-action bg-transparent text-white">
                        <i class="fas fa-chart-bar me-2"></i>Reports
    
                    </a>
                    <?php if ($current_user['role'] === 'admin'): ?>
                    <a href="pending_users.php" class="list-group-item list-group-item-action bg-transparent text-white">
                        <i class="fas fa-user-clock me-2"></i>Pending Approvals
                        <?php if ($pending_count > 0): ?>
                            <span class="badge bg-danger float-end"><?php echo $pending_count; ?></span>
                        <?php endif; ?>
                    </a>
                    <?php endif; ?>
                    <a href="settings.php" class="list-group-item list-group-item-action bg-transparent text-white">
                        <i class="fas fa-cog me-2"></i>Settings
                    </a>
                    <a href="../logout.php" class="list-group-item list-group-item-action bg-transparent text-white" onclick="return confirm('Are you sure you want to logout?');">
                        <i class="fas fa-sign-out-alt me-2"></i>Logout
                    </a>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="flex-grow-1">
            <nav class="navbar navbar-light bg-light px-4 py-3">
                <span class="navbar-brand">Council Management</span>
                <div class="ms-auto">
                    <span><i class="fas fa-user-circle me-2"></i><?php echo htmlspecialchars($current_user['full_name']); ?></span>
                </div>
            </nav>

            <div class="container-fluid p-4">
                <?php if ($message): ?>
                    <div class="alert alert-success alert-dismissible fade show"><?php echo $message; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
                <?php endif; ?>
                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show"><?php echo $error; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
                <?php endif; ?>

                <!-- Toolbar -->
                <div class="d-flex justify-content-between mb-3">
                    <form method="GET" class="d-flex">
                        <input type="text" name="search" class="form-control me-2" placeholder="Search by name, position or email" value="<?php echo htmlspecialchars($search); ?>">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i></button>
                    </form>
                    <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addCouncilModal">
                        <i class="fas fa-plus me-2"></i>Add Council Member
                    </button>
                </div>

                <!-- Council Table -->
                <div class="card">
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Member</th>
                                        <th>Position</th>
                                        <th>Contact</th>
                                        <th>Email</th>
                                        <th>Term Start</th>
                                        <th>Term End</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($council_members)): ?>
                                        <tr><td colspan="8" class="text-center">No council members found.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($council_members as $c): ?>
                                        <tr>
                                            <td>
                                                <div class="member-name-with-photo">
                                                    <?php if (!empty($c['photo'])): ?>
                                                        <img src="../<?php echo htmlspecialchars($c['photo']); ?>" 
                                                             alt="<?php echo htmlspecialchars($c['full_name']); ?>" 
                                                             class="member-thumbnail"
                                                             onerror="this.style.display='none'">
                                                    <?php endif; ?>
                                                    <span class="full-name-display" 
                                                          onclick="viewMemberDetails(<?php echo $c['council_id']; ?>)"
                                                          data-bs-toggle="modal" 
                                                          data-bs-target="#viewCouncilModal">
                                                        <?php echo htmlspecialchars($c['full_name']); ?>
                                                        <i class="fas fa-eye ms-2 text-primary" style="font-size: 0.8rem;"></i>
                                                    </span>
                                                </div>
                                            </td>
                                            <td><?php echo htmlspecialchars($c['position']); ?></td>
                                            <td><?php echo htmlspecialchars($c['contact_number']); ?></td>
                                            <td><?php echo htmlspecialchars($c['email']); ?></td>
                                            <td><?php echo $c['term_start'] ? date('M d, Y', strtotime($c['term_start'])) : '-'; ?></td>
                                            <td><?php echo $c['term_end'] ? date('M d, Y', strtotime($c['term_end'])) : '-'; ?></td>
                                            <td>
                                                <span class="badge <?php echo $c['status'] == 'active' ? 'bg-success' : 'bg-secondary'; ?> status-badge" 
                                                      data-id="<?php echo $c['council_id']; ?>"
                                                      data-status="<?php echo $c['status']; ?>"
                                                      onclick="toggleStatus(<?php echo $c['council_id']; ?>, '<?php echo $c['status']; ?>')">
                                                    <?php echo ucfirst($c['status']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <button class="btn btn-sm btn-info view-btn" 
                                                    onclick="viewMemberDetails(<?php echo $c['council_id']; ?>)"
                                                    data-bs-toggle="modal" 
                                                    data-bs-target="#viewCouncilModal"
                                                    title="View Details">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <button class="btn btn-sm btn-primary edit-btn" 
                                                    data-id="<?php echo $c['council_id']; ?>"
                                                    data-lastname="<?php echo htmlspecialchars($c['last_name']); ?>"
                                                    data-firstname="<?php echo htmlspecialchars($c['first_name']); ?>"
                                                    data-middlename="<?php echo htmlspecialchars($c['middle_name']); ?>"
                                                    data-fullname="<?php echo htmlspecialchars($c['full_name']); ?>"
                                                    data-position="<?php echo htmlspecialchars($c['position']); ?>"
                                                    data-contact="<?php echo htmlspecialchars($c['contact_number']); ?>"
                                                    data-email="<?php echo htmlspecialchars($c['email']); ?>"
                                                    data-start="<?php echo $c['term_start']; ?>"
                                                    data-end="<?php echo $c['term_end']; ?>"
                                                    data-status="<?php echo $c['status']; ?>"
                                                    data-photo="<?php echo htmlspecialchars($c['photo'] ?? ''); ?>"
                                                    data-bs-toggle="modal" 
                                                    data-bs-target="#editCouncilModal"
                                                    title="Edit">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button class="btn btn-sm btn-danger delete-btn" 
                                                    data-id="<?php echo $c['council_id']; ?>" 
                                                    data-bs-toggle="modal" 
                                                    data-bs-target="#deleteCouncilModal"
                                                    title="Delete">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Pagination -->
                        <?php if ($total_pages > 1): ?>
                        <nav>
                            <ul class="pagination justify-content-center">
                                <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="?page=<?php echo $page-1; ?>&search=<?php echo urlencode($search); ?>">Previous</a>
                                </li>
                                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                    <a class="page-link" href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>"><?php echo $i; ?></a>
                                </li>
                                <?php endfor; ?>
                                <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="?page=<?php echo $page+1; ?>&search=<?php echo urlencode($search); ?>">Next</a>
                                </li>
                            </ul>
                        </nav>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- View Council Member Modal -->
    <div class="modal fade" id="viewCouncilModal" tabindex="-1" aria-labelledby="viewCouncilModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="viewCouncilModalLabel">
                        <i class="fas fa-user-tie me-2"></i>Council Member Details
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="viewModalBody">
                    <div class="text-center" id="viewLoading">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                        <p class="mt-2">Loading member details...</p>
                    </div>
                    <div id="viewContent" style="display: none;"></div>
                    <div id="viewError" style="display: none;" class="alert alert-danger"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary" id="viewEditBtn" style="display: none;">
                        <i class="fas fa-edit me-2"></i>Edit
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Council Modal -->
    <div class="modal fade" id="addCouncilModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" id="addCouncilForm">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="action" value="add">
                    <div class="modal-header">
                        <h5 class="modal-title">Add Council Member</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Last Name *</label>
                                <input type="text" class="form-control" name="last_name" id="add_last_name" required>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">First Name *</label>
                                <input type="text" class="form-control" name="first_name" id="add_first_name" required>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Middle Name</label>
                                <input type="text" class="form-control" name="middle_name" id="add_middle_name">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Full Name (auto-generated)</label>
                            <input type="text" class="form-control" id="add_full_name_display" readonly placeholder="Will be generated from names">
                            <input type="hidden" name="full_name" id="add_full_name">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Position *</label>
                            <select class="form-select" name="position" required>
                                <option value="">Select Position</option>
                                <?php foreach ($positions as $value => $label): ?>
                                    <option value="<?php echo htmlspecialchars($value); ?>"><?php echo htmlspecialchars($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Contact Number</label>
                            <input type="text" class="form-control" name="contact_number">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Email</label>
                            <input type="email" class="form-control" name="email">
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Term Start</label>
                                <input type="date" class="form-control" name="term_start">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Term End</label>
                                <input type="date" class="form-control" name="term_end">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Council Modal -->
    <div class="modal fade" id="editCouncilModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" id="editCouncilForm">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="council_id" id="edit_council_id">
                    <div class="modal-header">
                        <h5 class="modal-title">Edit Council Member</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <!-- Profile Picture Preview -->
                        <div class="text-center mb-3">
                            <div class="position-relative d-inline-block">
                                <img id="edit_photo_preview" src="../assets/images/default-avatar.png" 
                                     alt="Profile Preview" class="rounded-circle" 
                                     style="width: 100px; height: 100px; object-fit: cover; border: 3px solid #dee2e6;">
                                <button type="button" class="btn btn-sm btn-primary position-absolute bottom-0 end-0 rounded-circle" 
                                        style="width: 32px; height: 32px;" 
                                        onclick="document.getElementById('photo_upload_input').click();">
                                    <i class="fas fa-camera"></i>
                                </button>
                            </div>
                            <input type="file" id="photo_upload_input" accept="image/*" style="display: none;">
                            <div id="photo_upload_progress" class="photo-upload-progress">
                                <div class="progress">
                                    <div class="progress-bar progress-bar-striped progress-bar-animated" style="width: 0%"></div>
                                </div>
                            </div>
                            <div id="photo_actions" style="display: none; margin-top: 10px;">
                                <button type="button" class="btn btn-sm btn-danger" onclick="deletePhoto()">
                                    <i class="fas fa-trash me-1"></i>Delete Photo
                                </button>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Last Name *</label>
                                <input type="text" class="form-control" name="last_name" id="edit_last_name" required>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">First Name *</label>
                                <input type="text" class="form-control" name="first_name" id="edit_first_name" required>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Middle Name</label>
                                <input type="text" class="form-control" name="middle_name" id="edit_middle_name">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Full Name (auto-generated)</label>
                            <input type="text" class="form-control" id="edit_full_name_display" readonly>
                            <input type="hidden" name="full_name" id="edit_full_name">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Position *</label>
                            <select class="form-select" name="position" id="edit_position" required>
                                <option value="">Select Position</option>
                                <?php foreach ($positions as $value => $label): ?>
                                    <option value="<?php echo htmlspecialchars($value); ?>"><?php echo htmlspecialchars($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Contact Number</label>
                            <input type="text" class="form-control" name="contact_number" id="edit_contact">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Email</label>
                            <input type="email" class="form-control" name="email" id="edit_email">
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Term Start</label>
                                <input type="date" class="form-control" name="term_start" id="edit_term_start">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Term End</label>
                                <input type="date" class="form-control" name="term_end" id="edit_term_end">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Status</label>
                            <select class="form-select" name="status" id="edit_status">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteCouncilModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" id="deleteCouncilForm">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="council_id" id="delete_council_id">
                    <div class="modal-header">
                        <h5 class="modal-title">Confirm Deactivation</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p>Are you sure you want to deactivate this council member?</p>
                        <p class="text-muted small">This will also remove their profile picture.</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">Deactivate</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Photo Upload Modal -->
    <div class="modal fade" id="photoUploadModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-sm">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Upload Profile Picture</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="photoUploadForm" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="council_id" id="upload_council_id">
                        <div class="mb-3">
                            <label class="form-label">Select Image</label>
                            <input type="file" class="form-control" name="photo" id="photo_input" accept="image/*" required>
                            <small class="text-muted">Max size: 5MB. Allowed: JPG, PNG, GIF, WEBP</small>
                        </div>
                        <div id="uploadPreview" class="text-center mb-3" style="display: none;">
                            <img src="" alt="Preview" class="img-fluid rounded" style="max-height: 200px;">
                        </div>
                        <div id="uploadProgress" class="photo-upload-progress">
                            <div class="progress">
                                <div class="progress-bar progress-bar-striped progress-bar-animated" style="width: 0%"></div>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="uploadPhotoBtn">Upload</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        let currentViewId = null;
        let currentPhotoId = null;

        // Auto-generate full name from last, first, middle
        function updateFullName(prefix) {
            let lastName = $(`#${prefix}_last_name`).val().trim();
            let firstName = $(`#${prefix}_first_name`).val().trim();
            let middleName = $(`#${prefix}_middle_name`).val().trim();
            
            let fullName = '';
            if (lastName) fullName += lastName;
            if (firstName) fullName += (fullName ? ', ' : '') + firstName;
            if (middleName) fullName += ' ' + middleName;
            
            $(`#${prefix}_full_name_display`).val(fullName);
            $(`#${prefix}_full_name`).val(fullName);
        }

        // Add event listeners for add form
        $('#add_last_name, #add_first_name, #add_middle_name').on('keyup change', function() {
            updateFullName('add');
        });

        // Add event listeners for edit form
        $('#edit_last_name, #edit_first_name, #edit_middle_name').on('keyup change', function() {
            updateFullName('edit');
        });

        // Function to view member details
        function viewMemberDetails(id) {
            currentViewId = id;
            
            // Show loading, hide content and error
            $('#viewLoading').show();
            $('#viewContent').hide();
            $('#viewError').hide();
            $('#viewEditBtn').hide();
            
            // Fetch member details via AJAX
            $.ajax({
                url: 'get_council_member.php',
                type: 'GET',
                data: { id: id, t: new Date().getTime() },
                dataType: 'json',
                timeout: 10000,
                success: function(response) {
                    $('#viewLoading').hide();
                    
                    if (response && response.success) {
                        if (response.data) {
                            displayMemberDetails(response.data);
                            $('#viewEditBtn').show();
                        } else {
                            showError('No data received for this member');
                        }
                    } else {
                        let errorMsg = response.error || 'Unknown error occurred';
                        showError('Failed to load member details: ' + errorMsg);
                    }
                },
                error: function(xhr, status, error) {
                    $('#viewLoading').hide();
                    
                    let errorMsg = 'An error occurred while loading member details';
                    
                    if (status === 'timeout') {
                        errorMsg = 'Request timed out. Please try again.';
                    } else if (xhr.status === 404) {
                        errorMsg = 'The member details endpoint was not found. Please check if get_council_member.php exists.';
                    } else if (xhr.status === 500) {
                        errorMsg = 'Server error occurred. Please check the server logs.';
                    }
                    
                    showError(errorMsg);
                }
            });
        }

        // Function to display member details
        function displayMemberDetails(member) {
            const fullName = member.full_name || 'Unknown';
            const photoUrl = member.photo ? '../' + member.photo : '../assets/images/default-avatar.png';
            
            // Format dates
            let termStart = 'Not set';
            let termEnd = 'Not set';
            let createdDate = 'Unknown';
            let updatedDate = 'Never';
            
            try {
                if (member.term_start) {
                    termStart = new Date(member.term_start).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
                }
                
                if (member.term_end) {
                    termEnd = new Date(member.term_end).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
                }
                
                if (member.created_at) {
                    createdDate = new Date(member.created_at).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric', hour: '2-digit', minute: '2-digit' });
                }
                
                if (member.updated_at) {
                    updatedDate = new Date(member.updated_at).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric', hour: '2-digit', minute: '2-digit' });
                }
            } catch (e) {
                console.log('Date formatting error:', e);
            }
            
            const html = `
                <div class="profile-header text-center">
                    <div class="profile-image-container">
                        <img src="${photoUrl}" alt="${fullName}" class="profile-image" onerror="this.src='../assets/images/default-avatar.png'">
                        <div class="profile-image-actions">
                            <button class="btn-link" onclick="openPhotoUpload(${member.council_id})">
                                <i class="fas fa-camera me-1"></i>Change Photo
                            </button>
                        </div>
                    </div>
                    <h3>${fullName}</h3>
                    <p class="mb-0">
                        <span class="badge ${member.status === 'active' ? 'bg-success' : 'bg-secondary'}">${member.status ? member.status.toUpperCase() : 'UNKNOWN'}</span>
                    </p>
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="member-info-card">
                            <h5 class="mb-3"><i class="fas fa-briefcase me-2"></i>Position Information</h5>
                            <div class="info-label">Position</div>
                            <div class="info-value">${member.position || 'Not specified'}</div>
                            
                            <div class="info-label">Term Start</div>
                            <div class="info-value">${termStart}</div>
                            
                            <div class="info-label">Term End</div>
                            <div class="info-value">${termEnd}</div>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="member-info-card">
                            <h5 class="mb-3"><i class="fas fa-address-card me-2"></i>Contact Information</h5>
                            <div class="info-label">Contact Number</div>
                            <div class="info-value">${member.contact_number || 'Not provided'}</div>
                            
                            <div class="info-label">Email Address</div>
                            <div class="info-value">${member.email || 'Not provided'}</div>
                        </div>
                    </div>
                </div>
                
                <div class="member-info-card">
                    <h5 class="mb-3"><i class="fas fa-clock me-2"></i>System Information</h5>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="info-label">Created At</div>
                            <div class="info-value">${createdDate}</div>
                        </div>
                        <div class="col-md-6">
                            <div class="info-label">Last Updated</div>
                            <div class="info-value">${updatedDate}</div>
                        </div>
                    </div>
                </div>
            `;
            
            $('#viewContent').html(html);
            $('#viewContent').show();
        }

        // Function to show error
        function showError(message) {
            $('#viewError').html(`
                <div class="error-details">
                    <strong>Error:</strong> ${message}<br>
                    <small>Please check the browser console (F12) for more details.</small>
                </div>
            `).show();
        }

        // Edit button click handler
        $('#viewEditBtn').on('click', function() {
            if (currentViewId) {
                $('#viewCouncilModal').modal('hide');
                
                // Find the member in the table
                const memberRow = $('span.full-name-display[onclick*="' + currentViewId + '"]').first().closest('tr');
                
                if (memberRow.length) {
                    const editBtn = memberRow.find('.edit-btn');
                    
                    if (editBtn.length) {
                        // Populate edit modal
                        $('#edit_council_id').val(editBtn.data('id'));
                        $('#edit_last_name').val(editBtn.data('lastname'));
                        $('#edit_first_name').val(editBtn.data('firstname'));
                        $('#edit_middle_name').val(editBtn.data('middlename') || '');
                        $('#edit_full_name_display').val(editBtn.data('fullname'));
                        $('#edit_full_name').val(editBtn.data('fullname'));
                        $('#edit_position').val(editBtn.data('position'));
                        $('#edit_contact').val(editBtn.data('contact') || '');
                        $('#edit_email').val(editBtn.data('email') || '');
                        $('#edit_term_start').val(editBtn.data('start') || '');
                        $('#edit_term_end').val(editBtn.data('end') || '');
                        $('#edit_status').val(editBtn.data('status') || 'active');
                        
                        // Set photo preview
                        const photo = editBtn.data('photo');
                        if (photo) {
                            $('#edit_photo_preview').attr('src', '../' + photo);
                            $('#photo_actions').show();
                        } else {
                            $('#edit_photo_preview').attr('src', '../assets/images/default-avatar.png');
                            $('#photo_actions').hide();
                        }
                        
                        setTimeout(function() {
                            $('#editCouncilModal').modal('show');
                        }, 500);
                    }
                }
            }
        });

        // Populate edit modal from edit button
        $('.edit-btn').on('click', function() {
            var btn = $(this);
            $('#edit_council_id').val(btn.data('id'));
            $('#edit_last_name').val(btn.data('lastname') || '');
            $('#edit_first_name').val(btn.data('firstname') || '');
            $('#edit_middle_name').val(btn.data('middlename') || '');
            $('#edit_full_name_display').val(btn.data('fullname') || '');
            $('#edit_full_name').val(btn.data('fullname') || '');
            $('#edit_position').val(btn.data('position'));
            $('#edit_contact').val(btn.data('contact') || '');
            $('#edit_email').val(btn.data('email') || '');
            $('#edit_term_start').val(btn.data('start') || '');
            $('#edit_term_end').val(btn.data('end') || '');
            $('#edit_status').val(btn.data('status') || 'active');
            
            // Set photo preview
            const photo = btn.data('photo');
            if (photo) {
                $('#edit_photo_preview').attr('src', '../' + photo);
                $('#photo_actions').show();
            } else {
                $('#edit_photo_preview').attr('src', '../assets/images/default-avatar.png');
                $('#photo_actions').hide();
            }
        });

        // Set delete id
        $('.delete-btn').on('click', function() {
            $('#delete_council_id').val($(this).data('id'));
        });

        // Toggle status function
        function toggleStatus(id, currentStatus) {
            if (confirm('Are you sure you want to change the status of this council member?')) {
                var newStatus = currentStatus === 'active' ? 'inactive' : 'active';
                
                $.ajax({
                    url: window.location.href,
                    type: 'POST',
                    data: {
                        action: 'toggle_status',
                        council_id: id,
                        new_status: newStatus,
                        csrf_token: '<?php echo $csrf_token; ?>'
                    },
                    dataType: 'json',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    success: function(response) {
                        if (response.success) {
                            var badge = $('span.status-badge[data-id="' + id + '"]');
                            badge.text(response.new_status.charAt(0).toUpperCase() + response.new_status.slice(1));
                            badge.attr('data-status', response.new_status);
                            
                            if (response.new_status === 'active') {
                                badge.removeClass('bg-secondary').addClass('bg-success');
                            } else {
                                badge.removeClass('bg-success').addClass('bg-secondary');
                            }
                            
                            showAlert('success', response.message);
                        } else {
                            showAlert('danger', response.error || 'Failed to update status');
                        }
                    },
                    error: function() {
                        showAlert('danger', 'An error occurred while updating status');
                    }
                });
            }
        }

        // Function to show alerts
        function showAlert(type, message) {
            var alertHtml = '<div class="alert alert-' + type + ' alert-dismissible fade show">' + 
                            message + 
                            '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>' +
                            '</div>';
            $('.container-fluid.p-4').prepend(alertHtml);
            
            setTimeout(function() {
                $('.alert').alert('close');
            }, 5000);
        }

        // Clear modal content when closed
        $('#viewCouncilModal').on('hidden.bs.modal', function () {
            $('#viewLoading').show();
            $('#viewContent').hide().empty();
            $('#viewError').hide();
            $('#viewEditBtn').hide();
            currentViewId = null;
        });

        // Photo upload functionality
        function openPhotoUpload(councilId) {
            currentPhotoId = councilId;
            $('#upload_council_id').val(councilId);
            $('#photo_input').val('');
            $('#uploadPreview').hide().find('img').attr('src', '');
            $('#uploadProgress').find('.progress-bar').css('width', '0%');
            $('#photoUploadModal').modal('show');
        }

        // Preview image before upload
        $('#photo_input').on('change', function() {
            const file = this.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    $('#uploadPreview').show().find('img').attr('src', e.target.result);
                };
                reader.readAsDataURL(file);
            } else {
                $('#uploadPreview').hide();
            }
        });

        // Upload photo
        $('#uploadPhotoBtn').on('click', function() {
            const formData = new FormData($('#photoUploadForm')[0]);
            const file = $('#photo_input')[0].files[0];
            
            if (!file) {
                alert('Please select a file to upload.');
                return;
            }
            
            // Validate file type
            const validTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            if (!validTypes.includes(file.type)) {
                alert('Invalid file type. Please select a JPG, PNG, GIF, or WEBP image.');
                return;
            }
            
            // Validate file size (5MB)
            if (file.size > 5 * 1024 * 1024) {
                alert('File size must be less than 5MB.');
                return;
            }
            
            $('#uploadProgress').show();
            let progress = 0;
            const interval = setInterval(function() {
                progress += 10;
                $('#uploadProgress').find('.progress-bar').css('width', progress + '%');
                if (progress >= 100) clearInterval(interval);
            }, 100);
            
            $.ajax({
                url: 'upload_council_photo.php',
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function(response) {
                    clearInterval(interval);
                    $('#uploadProgress').find('.progress-bar').css('width', '100%');
                    
                    setTimeout(function() {
                        $('#photoUploadModal').modal('hide');
                        
                        if (response.success) {
                            // Update photo in view modal if open
                            if (currentViewId == currentPhotoId) {
                                $('#viewContent').find('.profile-image').attr('src', response.photo_url + '?t=' + new Date().getTime());
                            }
                            
                            // Update photo in table
                            const memberRow = $('span.full-name-display[onclick*="' + currentPhotoId + '"]').first().closest('tr');
                            const memberThumb = memberRow.find('.member-thumbnail');
                            if (memberThumb.length) {
                                memberThumb.attr('src', response.photo_url + '?t=' + new Date().getTime());
                            } else {
                                // Add thumbnail if not exists
                                const nameCell = memberRow.find('td:first-child .member-name-with-photo');
                                if (nameCell.length) {
                                    nameCell.prepend('<img src="' + response.photo_url + '?t=' + new Date().getTime() + '" alt="Thumbnail" class="member-thumbnail">');
                                }
                            }
                            
                            // Update edit modal preview
                            if ($('#edit_council_id').val() == currentPhotoId) {
                                $('#edit_photo_preview').attr('src', response.photo_url + '?t=' + new Date().getTime());
                                $('#photo_actions').show();
                            }
                            
                            showAlert('success', response.message);
                        } else {
                            showAlert('danger', response.message || 'Failed to upload photo.');
                        }
                        
                        $('#uploadProgress').hide();
                    }, 500);
                },
                error: function() {
                    clearInterval(interval);
                    $('#uploadProgress').hide();
                    showAlert('danger', 'An error occurred while uploading the photo.');
                }
            });
        });

        // Delete photo function
        function deletePhoto() {
            if (!confirm('Are you sure you want to delete this profile picture?')) {
                return;
            }
            
            const councilId = $('#edit_council_id').val();
            
            $.ajax({
                url: 'delete_council_photo.php',
                type: 'POST',
                data: {
                    council_id: councilId,
                    csrf_token: '<?php echo $csrf_token; ?>'
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        // Update preview
                        $('#edit_photo_preview').attr('src', '../assets/images/default-avatar.png');
                        $('#photo_actions').hide();
                        
                        // Update table thumbnail
                        const memberRow = $('span.full-name-display[onclick*="' + councilId + '"]').first().closest('tr');
                        memberRow.find('.member-thumbnail').remove();
                        
                        // Update view modal if open
                        if (currentViewId == councilId) {
                            $('#viewContent').find('.profile-image').attr('src', '../assets/images/default-avatar.png');
                        }
                        
                        showAlert('success', response.message);
                    } else {
                        showAlert('danger', response.message || 'Failed to delete photo.');
                    }
                },
                error: function() {
                    showAlert('danger', 'An error occurred while deleting the photo.');
                }
            });
        }

        // Initialize photo upload trigger from edit modal
        $('#photo_upload_input').on('change', function() {
            const file = this.files[0];
            if (file) {
                const councilId = $('#edit_council_id').val();
                
                // Validate file
                const validTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                if (!validTypes.includes(file.type)) {
                    alert('Invalid file type. Please select a JPG, PNG, GIF, or WEBP image.');
                    return;
                }
                
                if (file.size > 5 * 1024 * 1024) {
                    alert('File size must be less than 5MB.');
                    return;
                }
                
                // Show progress
                $('#photo_upload_progress').show();
                let progress = 0;
                const interval = setInterval(function() {
                    progress += 10;
                    $('#photo_upload_progress').find('.progress-bar').css('width', progress + '%');
                    if (progress >= 100) clearInterval(interval);
                }, 100);
                
                // Upload file
                const formData = new FormData();
                formData.append('csrf_token', '<?php echo $csrf_token; ?>');
                formData.append('council_id', councilId);
                formData.append('photo', file);
                
                $.ajax({
                    url: 'upload_council_photo.php',
                    type: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    success: function(response) {
                        clearInterval(interval);
                        $('#photo_upload_progress').find('.progress-bar').css('width', '100%');
                        
                        setTimeout(function() {
                            $('#photo_upload_progress').hide();
                            
                            if (response.success) {
                                $('#edit_photo_preview').attr('src', response.photo_url + '?t=' + new Date().getTime());
                                $('#photo_actions').show();
                                
                                // Update table thumbnail
                                const memberRow = $('span.full-name-display[onclick*="' + councilId + '"]').first().closest('tr');
                                const memberThumb = memberRow.find('.member-thumbnail');
                                if (memberThumb.length) {
                                    memberThumb.attr('src', response.photo_url + '?t=' + new Date().getTime());
                                } else {
                                    const nameCell = memberRow.find('td:first-child .member-name-with-photo');
                                    nameCell.prepend('<img src="' + response.photo_url + '?t=' + new Date().getTime() + '" alt="Thumbnail" class="member-thumbnail">');
                                }
                                
                                showAlert('success', response.message);
                            } else {
                                showAlert('danger', response.message || 'Failed to upload photo.');
                            }
                        }, 500);
                    },
                    error: function() {
                        clearInterval(interval);
                        $('#photo_upload_progress').hide();
                        showAlert('danger', 'An error occurred while uploading the photo.');
                    }
                });
            }
        });
    </script>
</body>
</html>