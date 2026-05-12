<?php
// user/upload_member_photo.php
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
    $response = ['success' => false, 'message' => 'Member not found'];
    header('Content-Type: application/json');
    echo json_encode($response);
    exit();
}

$member_code = $member['member_code'];
$response = ['success' => false, 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $response['message'] = 'Invalid security token.';
    } else {
        // Check if file was uploaded
        if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['photo'];
            
            // Validate file type
            $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $max_size = 5 * 1024 * 1024; // 5MB
            
            if (!in_array($file['type'], $allowed_types)) {
                $response['message'] = 'Only JPG, PNG, GIF, and WEBP images are allowed.';
            } elseif ($file['size'] > $max_size) {
                $response['message'] = 'File size must be less than 5MB.';
            } else {
                // Create upload directory if it doesn't exist
                $upload_dir = '../uploads/members/';
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                
                // Generate unique filename
                $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
                $filename = 'member_' . $member_code . '_' . time() . '.' . $extension;
                $filepath = $upload_dir . $filename;
                
                // Move uploaded file
                if (move_uploaded_file($file['tmp_name'], $filepath)) {
                    $photo_path = 'uploads/members/' . $filename;
                    
                    // Check if there's already a pending request
                    $pending = $db->getSingle(
                        "SELECT request_id FROM member_photo_requests 
                         WHERE member_code = ? AND status = 'pending'",
                        [$member_code], 's'
                    );
                    
                    if ($pending) {
                        // Delete the uploaded file
                        unlink($filepath);
                        $response['message'] = 'You already have a pending photo change request. Please wait for approval.';
                    } else {
                        // Create approval request
                        $result = $db->execute(
                            "INSERT INTO member_photo_requests (member_code, photo_path, requested_by, requested_at) 
                             VALUES (?, ?, ?, NOW())",
                            [$member_code, $photo_path, $current_user['user_id']],
                            'ssi'
                        );
                        
                        if ($result) {
                            Security::logEvent('MEMBER_PHOTO_REQUEST', "Member $member_code requested photo change");
                            
                            // Create notification for admin
                            $admin = $db->getAll("SELECT user_id FROM users WHERE role = 'admin' AND is_active = 1");
                            foreach ($admin as $adm) {
                                createNotification(
                                    $db,
                                    $adm['user_id'],
                                    "Profile Photo Change Request",
                                    "Member " . ($member['first_name'] . ' ' . $member['last_name']) . " has requested to change their profile photo.",
                                    'account',
                                    '../admin/members.php?view=pending_photos',
                                    'user-circle'
                                );
                            }
                            
                            $response['success'] = true;
                            $response['message'] = 'Photo uploaded successfully! Awaiting admin approval.';
                            $response['photo_preview'] = '../' . $photo_path;
                            $response['pending'] = true;
                        } else {
                            unlink($filepath);
                            $response['message'] = 'Failed to save photo request.';
                        }
                    }
                } else {
                    $response['message'] = 'Failed to upload file.';
                }
            }
        } else {
            $response['message'] = 'No file uploaded or upload error.';
        }
    }
}

header('Content-Type: application/json');
echo json_encode($response);
?>