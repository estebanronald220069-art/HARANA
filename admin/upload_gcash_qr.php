<?php
// admin/upload_gcash_qr.php
// Handles uploading the org's GCash QR code
require_once '../includes/config.php';
require_once '../includes/db_connection.php';
require_once '../includes/security.php';
require_once '../includes/auth.php';

$auth->requireLogin();
$auth->requireRole('admin');
$current_user = $auth->getCurrentUser();

$db = Database::getInstance();

header('Content-Type: application/json');

$response = ['success' => false, 'message' => '', 'path' => ''];

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

if (!isset($_FILES['qr_image']) || $_FILES['qr_image']['error'] !== UPLOAD_ERR_OK) {
    $response['message'] = 'No file uploaded.';
    echo json_encode($response);
    exit();
}

$file = $_FILES['qr_image'];

// Validate
$allowed_types = ['image/jpeg', 'image/png', 'image/webp'];
$max_size      = 5 * 1024 * 1024; // 5MB

if (!in_array($file['type'], $allowed_types, true)) {
    $response['message'] = 'Only JPG, PNG, or WEBP images are allowed.';
    echo json_encode($response);
    exit();
}
if ($file['size'] > $max_size) {
    $response['message'] = 'File is larger than 5MB.';
    echo json_encode($response);
    exit();
}

// Ensure upload dir exists
$upload_dir = '../uploads/settings/';
if (!is_dir($upload_dir)) {
    @mkdir($upload_dir, 0755, true);
}

// Generate unique filename
$ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
$filename = 'gcash-qr-' . time() . '.' . $ext;
$filepath = $upload_dir . $filename;
$relative = 'uploads/settings/' . $filename;

if (!move_uploaded_file($file['tmp_name'], $filepath)) {
    $response['message'] = 'Failed to save the uploaded file.';
    echo json_encode($response);
    exit();
}

// Get old QR path to delete it
$old = $db->getSingle(
    "SELECT setting_value FROM app_settings WHERE setting_key = 'gcash_qr_path'"
);

// Save new path
$ok = $db->execute(
    "INSERT INTO app_settings (setting_key, setting_value) VALUES ('gcash_qr_path', ?)
     ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)",
    [$relative], 's'
);

if (!$ok) {
    @unlink($filepath);
    $response['message'] = 'Failed to save setting in database.';
    echo json_encode($response);
    exit();
}

// Delete old file if different
if ($old && !empty($old['setting_value']) && $old['setting_value'] !== $relative) {
    $old_file = '../' . $old['setting_value'];
    if (file_exists($old_file) && is_file($old_file)) {
        @unlink($old_file);
    }
}

Security::logEvent('GCASH_QR_UPLOADED', "Admin uploaded new GCash QR: $relative");

$response['success'] = true;
$response['message'] = 'GCash QR code uploaded successfully.';
$response['path']    = $relative;

echo json_encode($response);