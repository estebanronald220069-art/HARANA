<?php
// admin/save_gcash_info.php
require_once '../includes/config.php';
require_once '../includes/db_connection.php';
require_once '../includes/security.php';
require_once '../includes/auth.php';

$auth->requireLogin();
$auth->requireRole('admin');
$current_user = $auth->getCurrentUser();

$db = Database::getInstance();

header('Content-Type: application/json');

$response = ['success' => false, 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $response['message'] = 'Invalid request.';
    echo json_encode($response);
    exit();
}

if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
    $response['message'] = 'Invalid security token.';
    echo json_encode($response);
    exit();
}

$mobile = trim(Security::sanitize($_POST['gcash_mobile'] ?? ''));
$name   = trim(Security::sanitize($_POST['gcash_name']   ?? ''));
$userid = trim(Security::sanitize($_POST['gcash_user_id'] ?? ''));

$pairs = [
    'gcash_mobile'  => $mobile,
    'gcash_name'    => $name,
    'gcash_user_id' => $userid,
];

foreach ($pairs as $key => $val) {
    $db->execute(
        "INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)",
        [$key, $val], 'ss'
    );
}

Security::logEvent('GCASH_INFO_UPDATED', 'Admin updated GCash account info');

$response['success'] = true;
$response['message'] = 'GCash account info saved.';

echo json_encode($response);