<?php
// admin/get_council_member.php
require_once '../includes/config.php';
require_once '../includes/db_connection.php';
require_once '../includes/security.php';
require_once '../includes/auth.php';

$auth->requireLogin();

$db = Database::getInstance();
$response = ['success' => false, 'error' => ''];

if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $id = (int)$_GET['id'];
    
    $member = $db->getSingle("SELECT * FROM council WHERE council_id = ?", [$id], 'i');
    
    if ($member) {
        $response['success'] = true;
        $response['data'] = $member;
    } else {
        $response['error'] = 'Council member not found.';
    }
} else {
    $response['error'] = 'Invalid ID.';
}

header('Content-Type: application/json');
echo json_encode($response);
?>