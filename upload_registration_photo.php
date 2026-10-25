<?php
// upload_registration_photo.php
// Handles registration uploads: ID photo, medical cert, birth cert
require_once 'includes/config.php';
require_once 'includes/db_connection.php';
require_once 'includes/security.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'message' => 'Invalid security token']);
    exit;
}

$doc_type = $_POST['doc_type'] ?? 'photo';

$doc_config = [
    'photo' => [
        'prefix' => 'reg_photo_',
        'square' => true,
        'types'  => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
        'max'    => 10 * 1024 * 1024
    ],
    'medical_certificate' => [
        'prefix' => 'medical_',
        'square' => false,
        'types'  => ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'],
        'max'    => 10 * 1024 * 1024
    ],
    'birth_certificate' => [
        'prefix' => 'birth_',
        'square' => false,
        'types'  => ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'],
        'max'    => 10 * 1024 * 1024
    ],
];

if (!isset($doc_config[$doc_type])) {
    echo json_encode(['success' => false, 'message' => 'Unknown document type']);
    exit;
}
$cfg = $doc_config[$doc_type];

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => 'No file uploaded or upload error']);
    exit;
}

$file = $_FILES['file'];

if (!in_array($file['type'], $cfg['types'], true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid file type. Allowed: JPG, PNG, WEBP, PDF']);
    exit;
}
if ($file['size'] > $cfg['max']) {
    echo json_encode(['success' => false, 'message' => 'File must be under 10MB']);
    exit;
}

if ($cfg['square'] && strpos($file['type'], 'image/') === 0) {
    $size_info = @getimagesize($file['tmp_name']);
    if ($size_info === false) {
        echo json_encode(['success' => false, 'message' => 'Could not read image']);
        exit;
    }
    $w = $size_info[0];
    $h = $size_info[1];
    if ($w < 300 || $h < 300) {
        echo json_encode(['success' => false, 'message' => 'Image must be at least 300x300 pixels']);
        exit;
    }
    $ratio = $w / $h;
    if ($ratio < 0.9 || $ratio > 1.1) {
        echo json_encode(['success' => false, 'message' => 'ID photo must be roughly square (1:1)']);
        exit;
    }
}

$upload_dir = __DIR__ . '/uploads/temp/';
if (!is_dir($upload_dir)) {
    @mkdir($upload_dir, 0777, true);
}

$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
$session_tag = session_id() ?: uniqid();
$filename = $cfg['prefix'] . substr($session_tag, 0, 8) . '_' . time() . '.' . $ext;
$filepath = $upload_dir . $filename;

if (!move_uploaded_file($file['tmp_name'], $filepath)) {
    echo json_encode(['success' => false, 'message' => 'Failed to save file on server']);
    exit;
}

if (!isset($_SESSION['temp_registration_files'])) {
    $_SESSION['temp_registration_files'] = [];
}

$previous = $_SESSION['temp_registration_files'][$doc_type] ?? null;
if ($previous && $previous !== $filename) {
    $old = $upload_dir . $previous;
    if (file_exists($old)) @unlink($old);
}

$_SESSION['temp_registration_files'][$doc_type] = $filename;

if ($doc_type === 'photo') {
    $_SESSION['temp_registration_photo'] = $filename;
}

echo json_encode([
    'success' => true,
    'message' => 'Uploaded successfully',
    'file'    => $filename,
    'preview' => 'uploads/temp/' . $filename,
]);
exit;