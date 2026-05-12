<?php
// admin/get_member.php
require_once '../includes/config.php';
require_once '../includes/db_connection.php';
require_once '../includes/auth.php';

$auth->requireLogin();

$db = Database::getInstance();

// Accept both 'code' and 'id' parameters
$member_code = isset($_POST['code']) ? Security::sanitize($_POST['code']) : '';
$member_id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

// Try to find by member_code first (primary identifier)
if (!empty($member_code)) {
    $member = $db->getSingle("SELECT * FROM members WHERE member_code = ?", [$member_code], 's');
} 
// Fallback to member_id (if your table has it)
elseif ($member_id > 0) {
    $member = $db->getSingle("SELECT * FROM members WHERE member_id = ?", [$member_id], 'i');
} 
else {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid member identifier']);
    exit;
}

if (!$member) {
    http_response_code(404);
    echo json_encode(['error' => 'Member not found']);
    exit;
}

// Clean up date fields
if ($member['birth_date'] == '0000-00-00') {
    $member['birth_date'] = null;
}
if ($member['screening_date'] == '0000-00-00') {
    $member['screening_date'] = null;
}
if ($member['date_joined'] == '0000-00-00') {
    $member['date_joined'] = null;
}
if ($member['date_registered'] == '0000-00-00') {
    $member['date_registered'] = null;
}

header('Content-Type: application/json');
echo json_encode($member);
exit;