<?php
// api/leader/members.php — FINAL VERSION
//
// ── FIX 2026-10 ──────────────────────────────────────────────────────────
// The `paid_count` subquery previously did:
//
//     WHERE p.member_id = m.member_code
//
// `payments.member_id` is INT; `members.member_code` is VARCHAR. MySQL
// silently casts alphanumeric codes ('M4546', 'M9078') to 0, so the join
// degenerated into `WHERE p.member_id = 0` — which matched *every*
// alphanumeric payment in the system. Result:
//
//     • Every alphanumeric member showed PAID once *any* alphanumeric
//       payment existed for that period
//     • The leader could not tell which members still owed money
//     • New collections for those members were blocked by the dashboard
//
// After the api_batch_confirm + data-migration fixes, `payments.member_code`
// is the canonical identity for every row. Both sides of the join now use
// VARCHAR member_code, so numeric and alphanumeric codes behave identically.
// ────────────────────────────────────────────────────────────────────────

require_once __DIR__ . '/../_bootstrap.php';
$db = Database::getInstance();
$me = require_bearer($db);

$period = $_GET['period'] ?? date('Y-m');

// ------------------------------------------------------------
// 1. Which groups does this leader manage?
// ------------------------------------------------------------
$groups = $db->getAll(
    "SELECT g.group_id, g.group_name, c.chapter_name
     FROM leader_group_assignments a
     JOIN groups g   ON g.group_id   = a.group_id
     JOIN chapters c ON c.chapter_id = g.chapter_id
     WHERE a.leader_user_id = ?
     ORDER BY c.chapter_name, g.group_name",
    [$me['user_id']], 'i'
);

if (empty($groups)) {
    api_out([
        'success' => true,
        'period'  => $period,
        'groups'  => [],
        'members' => [],
        'message' => 'You are not yet assigned to any group.',
    ]);
}

$group_ids = array_map(fn($g) => (int)$g['group_id'], $groups);
$placeholders = implode(',', array_fill(0, count($group_ids), '?'));

// ------------------------------------------------------------
// 2. Active members in those groups
// ------------------------------------------------------------
$members = $db->getAll(
    "SELECT m.member_code,
            CONCAT(m.first_name,' ',m.last_name) AS name,
            m.contact_number,
            m.monthly_contribution,
            m.group_id,
            m.barangay,
            m.address,
            m.present_address,
            m.street,
            m.city,
            m.province,
            (SELECT COUNT(*) FROM payments p
              WHERE p.member_code = m.member_code
                AND p.payment_status = 'confirmed'
                AND COALESCE(p.period_month, DATE_FORMAT(p.payment_date,'%Y-%m')) = ?) AS paid_count,
            (SELECT COUNT(*) FROM collection_items ci
              JOIN collection_batches cb ON cb.batch_id = ci.batch_id
              WHERE ci.member_code = m.member_code
                AND ci.period_month = ?
                AND cb.status IN ('submitted','open')) AS pending_count
     FROM members m
     WHERE m.group_id IN ($placeholders) AND m.status = 'active'
     ORDER BY m.last_name ASC, m.first_name ASC",
    array_merge([$period, $period], $group_ids),
    'ss' . str_repeat('i', count($group_ids))
);

foreach ($members as &$m) {
    $m['monthly_contribution'] = (float)$m['monthly_contribution'];
    $m['already_paid_period']  = ((int)$m['paid_count']   > 0);
    $m['already_in_batch']     = ((int)$m['pending_count'] > 0);
}
unset($m);

log_sync($db, $me['user_id'], 'leader/members?period=' . $period);

api_out([
    'success' => true,
    'period'  => $period,
    'groups'  => $groups,
    'members' => $members,
]);