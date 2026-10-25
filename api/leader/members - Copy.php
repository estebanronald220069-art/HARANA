<?php
require_once __DIR__ . '/../_bootstrap.php';
$db = Database::getInstance();
$me = require_bearer($db);

$period = $_GET['period'] ?? date('Y-m');

// Which groups does this leader manage?
$groups = $db->getAll(
    "SELECT g.group_id, g.group_name, c.chapter_name
     FROM leader_group_assignments a
     JOIN groups g   ON g.group_id   = a.group_id
     JOIN chapters c ON c.chapter_id = g.chapter_id
     WHERE a.leader_user_id = ?",
    [$me['user_id']], 'i'
);

if (empty($groups)) {
    api_out(['success' => true, 'period' => $period, 'groups' => [], 'members' => []]);
}

$group_ids = array_column($groups, 'group_id');
$ph = implode(',', array_fill(0, count($group_ids), '?'));

$members = $db->getAll(
    "SELECT m.member_code,
            CONCAT(m.first_name,' ',m.last_name) AS name,
            m.contact_number,
            m.monthly_contribution,
            m.group_id,
            m.barangay,
            (SELECT COUNT(*) FROM payments p
              WHERE p.member_id = m.member_code
                AND p.payment_status = 'confirmed'
                AND DATE_FORMAT(p.payment_date,'%Y-%m') = ?) AS paid_count,
            (SELECT COUNT(*) FROM collection_items ci
              JOIN collection_batches cb ON cb.batch_id = ci.batch_id
              WHERE ci.member_code = m.member_code
                AND cb.period_month = ?
                AND cb.status IN ('submitted','open')) AS pending_count
     FROM members m
     WHERE m.group_id IN ($ph) AND m.status = 'active'
     ORDER BY m.last_name ASC, m.first_name ASC",
    array_merge([$period, $period], $group_ids),
    'ss' . str_repeat('i', count($group_ids))
);

foreach ($members as &$m) {
    $m['monthly_contribution'] = (float)$m['monthly_contribution'];
    $m['already_paid_period']  = ((int)$m['paid_count'] > 0);
    $m['already_in_batch']     = ((int)$m['pending_count'] > 0);
}

log_sync($db, $me['user_id'], 'leader/members?period=' . $period);

api_out([
    'success' => true,
    'period'  => $period,
    'groups'  => $groups,
    'members' => $members,
]);