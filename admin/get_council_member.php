<?php
// admin/get_council_member.php
require_once '../includes/config.php';
require_once '../includes/db_connection.php';
require_once '../includes/security.php';
require_once '../includes/auth.php';

$auth->requireLogin();
$db = Database::getInstance();

header('Content-Type: application/json');

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid ID']);
    exit();
}

$id = (int)$_GET['id'];

// Select all columns from the council table
$member = $db->getSingle("SELECT * FROM council WHERE council_id = ?", [$id], 'i');

if ($member) {
    // Return the data as is - already using council_id which is correct
    echo json_encode(['success' => true, 'data' => $member]);
} else {
    echo json_encode(['success' => false, 'error' => 'Member not found']);
}
?>