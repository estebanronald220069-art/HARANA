<?php
// admin/upload_council_photo.php
require_once '../includes/config.php';
require_once '../includes/db_connection.php';
require_once '../includes/security.php';
require_once '../includes/auth.php';

$auth->requireLogin();
$current_user = $auth->getCurrentUser();

$db = Database::getInstance();
$response = ['success' => false, 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $response['message'] = 'Invalid security token.';
    } else {
        $council_id = (int)$_POST['council_id'];
        
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
                $upload_dir = '../uploads/council/';
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                
                // Generate unique filename
                $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
                $filename = 'council_' . $council_id . '_' . time() . '.' . $extension;
                $filepath = $upload_dir . $filename;
                
                // Move uploaded file
                if (move_uploaded_file($file['tmp_name'], $filepath)) {
                    // Get old photo to delete later
                    $old_photo = $db->getSingle("SELECT photo FROM council WHERE council_id = ?", [$council_id], 'i');
                    
                    // Update database with new photo path (store relative path)
                    $photo_path = 'uploads/council/' . $filename;
                    $result = $db->execute(
                        "UPDATE council SET photo = ?, updated_by = ? WHERE council_id = ?",
                        [$photo_path, $current_user['user_id'], $council_id],
                        'sii'
                    );
                    
                    if ($result) {
                        // Delete old photo if exists
                        if ($old_photo && $old_photo['photo'] && file_exists('../' . $old_photo['photo'])) {
                            unlink('../' . $old_photo['photo']);
                        }
                        
                        Security::logEvent('COUNCIL_PHOTO', "Updated photo for council member ID: $council_id");
                        $response['success'] = true;
                        $response['message'] = 'Photo uploaded successfully.';
                        $response['photo_url'] = '../' . $photo_path;
                    } else {
                        $response['message'] = 'Failed to update database.';
                        unlink($filepath);
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