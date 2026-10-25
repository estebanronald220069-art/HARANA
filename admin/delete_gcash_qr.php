<?php
// admin/delete_gcash_qr.php
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
    $response['message'] = 'Invalid request method.';
    echo json_encode($response);
    exit();
}

if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
    $response['message'] = 'Invalid security token.';
    echo json_encode($response);
    exit();
}

// Get current path
$current = $db->getSingle(
    "SELECT setting_value FROM app_settings WHERE setting_key = 'gcash_qr_path'"
);

if ($current && !empty($current['setting_value'])) {
    $file = '../' . $current['setting_value'];
    if (file_exists($file) && is_file($file)) {
        @unlink($file);
    }
}

// Clear setting
$db->execute(
    "UPDATE app_settings SET setting_value = NULL WHERE setting_key = 'gcash_qr_path'"
);

Security::logEvent('GCASH_QR_DELETED', 'Admin deleted the GCash QR code');

$response['success'] = true;
$response['message'] = 'GCash QR code removed.';

echo json_encode($response);