<?php
// admin/api_batch_lookup.php
require_once '../includes/config.php';
require_once '../includes/db_connection.php';
require_once '../includes/security.php';
require_once '../includes/auth.php';

$auth->requireLogin();
$current_user = $auth->getCurrentUser();
if ($current_user['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Admin only']);
    exit;
}

header('Content-Type: application/json');
$db = Database::getInstance();

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true) ?: [];

$batch_id = (string)($payload['b'] ?? '');
$qr_token = (string)($payload['t'] ?? '');
$signature = (string)($payload['s'] ?? '');

if ($batch_id === '' || $qr_token === '' || $signature === '') {
    echo json_encode(['success' => false, 'error' => 'Invalid QR payload']);
    exit;
}

// Verify HMAC (must match how the leader API signs it)
$expected = substr(hash_hmac('sha256', $batch_id . '|' . $qr_token, SECRET_KEY), 0, 16);
if (!hash_equals($expected, $signature)) {
    echo json_encode(['success' => false, 'error' => 'QR signature verification failed']);
    exit;
}

$batch = $db->getSingle(
    "SELECT b.*, u.full_name AS leader_name, g.group_name
     FROM collection_batches b
     JOIN users u  ON u.user_id  = b.leader_user_id
     JOIN groups g ON g.group_id = b.group_id
     WHERE b.batch_id = ?
       AND b.qr_token = ?
       AND b.status IN ('submitted','open')
       AND (b.qr_expires_at IS NULL OR b.qr_expires_at > NOW())",
    [$batch_id, $qr_token], 'ss'
);

if (!$batch) {
    echo json_encode(['success' => false, 'error' => 'Batch not found, already remitted, or QR expired']);
    exit;
}

$items = $db->getAll(
    "SELECT ci.*, CONCAT(m.first_name,' ',m.last_name) AS member_name
     FROM collection_items ci
     JOIN members m ON m.member_code = ci.member_code
     WHERE ci.batch_id = ?",
    [$batch_id], 's'
);

echo json_encode(['success' => true, 'batch' => $batch, 'items' => $items]);