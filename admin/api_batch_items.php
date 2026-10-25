<?php
// admin/api_batch_items.php
// Returns the member-payment rows of a pending/submitted batch so
// admin/remittances.php can render the confirm modal without a QR scan.

require_once '../includes/config.php';
require_once '../includes/db_connection.php';
require_once '../includes/security.php';
require_once '../includes/auth.php';

$auth->requireLogin();
$current_user = $auth->getCurrentUser();
if (($current_user['role'] ?? '') !== 'admin') {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'Admin only']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
$db = Database::getInstance();

$batch_id = (string)($_GET['batch_id'] ?? '');
if ($batch_id === '') {
    echo json_encode(['success' => false, 'error' => 'batch_id required']);
    exit;
}

// Match the guard used by api_batch_confirm.php / api_batch_lookup.php:
// only open or submitted batches should be viewable here.
$batch = $db->getSingle(
    "SELECT batch_id, status FROM collection_batches
     WHERE batch_id = ? AND status IN ('submitted','open')",
    [$batch_id], 's'
);
if (!$batch) {
    echo json_encode([
        'success' => false,
        'error'   => 'Batch not found or already remitted',
    ]);
    exit;
}

// LEFT JOIN so items survive if a member record was removed;
// enumerate columns instead of ci.* to avoid leaking internals.
$items = $db->getAll(
    "SELECT
         ci.item_id,
         ci.member_code,
         ci.period_month,
         ci.amount,
         ci.collected_at,
         ci.note,
         CONCAT(m.first_name, ' ', m.last_name) AS member_name
     FROM collection_items ci
     LEFT JOIN members m ON m.member_code = ci.member_code
     WHERE ci.batch_id = ?
     ORDER BY ci.collected_at ASC, ci.item_id ASC",
    [$batch_id], 's'
);

// Fall back to the raw code if the member row is gone.
foreach ($items as &$it) {
    if ($it['member_name'] === null || trim($it['member_name']) === '') {
        $it['member_name'] = (string)$it['member_code'];
    }
}
unset($it);

echo json_encode([
    'success' => true,
    'batch_id' => $batch_id,
    'status'   => $batch['status'],
    'items'    => $items,
]);