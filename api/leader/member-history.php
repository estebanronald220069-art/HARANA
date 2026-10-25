<?php
// api/leader/member-history.php — Per-member month-by-month payment status
//
// ── FIX 2026-10 ──────────────────────────────────────────────────────────
// The confirmed-payments query previously did:
//
//     WHERE member_id = ?
//
// `payments.member_id` is INT; `$member_code` is VARCHAR. MySQL silently
// casts alphanumeric codes ('M4546', 'M9078') to 0, so the query matched
// *every* alphanumeric payment in the database. Result:
//
//     • `paid_set` contained months belonging to OTHER alphanumeric members
//     • The leader app's Advance page hid months the member actually owed
//     • The leader app's Missed page showed "all caught up" for members
//       who were actually behind
//     • Leaders could not collect from M… members without creating dupes
//
// The query now uses `member_code` (VARCHAR), which is the canonical
// identity for every row after the api_batch_confirm + migration fixes.
// Numeric and alphanumeric codes behave identically.
// ────────────────────────────────────────────────────────────────────────

require_once __DIR__ . '/../_bootstrap.php';
$db = Database::getInstance();
$me = require_bearer($db);

$member_code = trim($_GET['member_code'] ?? '');
if ($member_code === '') {
    api_out(['success' => false, 'error' => 'member_code is required.']);
}

// ── Verify the leader manages this member's group ──────────────
$rows = $db->getAll(
    "SELECT m.member_code,
            CONCAT(m.first_name,' ',m.last_name) AS name,
            m.monthly_contribution,
            m.date_joined,
            m.group_id
     FROM members m
     JOIN leader_group_assignments a ON a.group_id = m.group_id
     WHERE m.member_code = ?
       AND a.leader_user_id = ?
       AND m.status = 'active'
     LIMIT 1",
    [$member_code, $me['user_id']],
    'si'
);

if (empty($rows)) {
    api_out(['success' => false, 'error' => 'Member not found or not assigned to you.']);
}

$member  = $rows[0];
$monthly = (float)$member['monthly_contribution'];
$joined  = $member['date_joined'] ?: date('Y-m-d');

// ── Build list of every period from join-month → current month ─
$periods = [];
$cursor  = new DateTime(date('Y-m-01', strtotime($joined)));
$end     = new DateTime(date('Y-m-01'));
$guard   = 0;
while ($cursor <= $end && $guard < 600) { // 50y safety cap
    $periods[] = $cursor->format('Y-m');
    $cursor->modify('+1 month');
    $guard++;
}
if (empty($periods)) {
    $periods[] = date('Y-m');
}

// ── Confirmed payments → set of paid periods ───────────────────
// FIX: keys on `member_code` (VARCHAR), not `member_id` (INT).
// Prefer period_month (new), fall back to payment_date (legacy rows).
$paid_rows = $db->getAll(
    "SELECT DISTINCT
            COALESCE(period_month, DATE_FORMAT(payment_date,'%Y-%m')) AS period
     FROM payments
     WHERE member_code = ? AND payment_status = 'confirmed'",
    [$member_code], 's'
);
$paid_set = array_flip(array_column($paid_rows, 'period'));

// ── Already in a submitted/open batch → pending periods ────────
// Read period from the ITEM, not the batch (a batch can span months).
// This query already used ci.member_code, so it's correct as-is.
$pending_rows = $db->getAll(
    "SELECT DISTINCT ci.period_month AS period
     FROM collection_items ci
     JOIN collection_batches cb ON cb.batch_id = ci.batch_id
     WHERE ci.member_code = ?
       AND cb.status IN ('submitted','open')
       AND ci.period_month IS NOT NULL",
    [$member_code], 's'
);
$pending_set = array_flip(array_column($pending_rows, 'period'));

$current_period = date('Y-m');

// ── Compose months + missed list ───────────────────────────────
$months = [];
$missed = [];
foreach ($periods as $p) {
    $status = 'unpaid';
    if (isset($paid_set[$p]))        $status = 'paid';
    elseif (isset($pending_set[$p])) $status = 'pending';

    $is_current = ($p === $current_period);
    $is_past    = ($p < $current_period);

    $months[] = [
        'period'     => $p,
        'label'      => date('F Y', strtotime($p . '-01')),
        'amount'     => $monthly,
        'status'     => $status,
        'is_current' => $is_current,
        'is_past'    => $is_past,
    ];

    if ($is_past && $status === 'unpaid') {
        $missed[] = $p;
    }
}

// ── Advance options: next 12 months after the current month ────
$advance = [];
$cursor  = new DateTime(date('Y-m-01'));
$cursor->modify('+1 month');
for ($i = 0; $i < 12; $i++) {
    $p = $cursor->format('Y-m');
    $advance[] = [
        'period' => $p,
        'label'  => date('F Y', strtotime($p . '-01')),
        'amount' => $monthly,
    ];
    $cursor->modify('+1 month');
}

log_sync($db, $me['user_id'], 'leader/member-history?member_code=' . $member_code);

api_out([
    'success' => true,
    'member' => [
        'member_code'          => $member['member_code'],
        'name'                 => $member['name'],
        'monthly_contribution' => $monthly,
        'date_joined'          => $joined,
        'group_id'             => (int)$member['group_id'],
    ],
    'months'          => $months,
    'missed_periods'  => $missed,
    'advance_options' => $advance,
]);