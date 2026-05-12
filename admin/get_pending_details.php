<?php
// admin/get_pending_details.php
require_once '../includes/config.php';
require_once '../includes/db_connection.php';
require_once '../includes/security.php';
require_once '../includes/auth.php';

$auth->requireLogin();
$auth->requireRole('admin');

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid ID']);
    exit;
}

$db = Database::getInstance();
$id = (int)$_GET['id'];

$details = $db->getSingle("SELECT * FROM pending_users WHERE id = ?", [$id], 'i');

if (!$details) {
    http_response_code(404);
    echo json_encode(['error' => 'Not found']);
    exit;
}

// Remove password from output
unset($details['password']);

header('Content-Type: application/json');
echo json_encode($details);