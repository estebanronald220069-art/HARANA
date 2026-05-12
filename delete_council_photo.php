<?php
// admin/delete_council_photo.php
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
        
        // Get current photo path
        $member = $db->getSingle("SELECT photo FROM council WHERE council_id = ?", [$council_id], 'i');
        
        if ($member && $member['photo']) {
            // Delete file
            $file_path = '../' . $member['photo'];
            if (file_exists($file_path)) {
                unlink($file_path);
            }
            
            // Update database
            $result = $db->execute(
                "UPDATE council SET photo = NULL, updated_by = ? WHERE council_id = ?",
                [$current_user['user_id'], $council_id],
                'ii'
            );
            
            if ($result) {
                Security::logEvent('COUNCIL_PHOTO_DELETE', "Deleted photo for council member ID: $council_id");
                $response['success'] = true;
                $response['message'] = 'Photo deleted successfully.';
            } else {
                $response['message'] = 'Failed to update database.';
            }
        } else {
            $response['message'] = 'No photo found for this member.';
        }
    }
}

header('Content-Type: application/json');
echo json_encode($response);
?>