<?php
// api/leader/batches.php — FINAL VERSION (item-level periods)
require_once __DIR__ . '/../_bootstrap.php';
$db = Database::getInstance();
$me = require_bearer($db);

$method = $_SERVER['REQUEST_METHOD'];

// ============================================================
// GET — list my batches
// ============================================================
if ($method === 'GET') {
    $rows = $db->getAll(
        "SELECT batch_id, period_month, group_id, total_amount, member_count,
                status, qr_token, qr_expires_at, remitted_at, created_at
         FROM collection_batches
         WHERE leader_user_id = ?
         ORDER BY created_at DESC
         LIMIT 30",
        [$me['user_id']], 'i'
    );
    log_sync($db, $me['user_id'], 'GET leader/batches');
    api_out(['success' => true, 'batches' => $rows]);
}

// ============================================================
// POST — create batch
// ============================================================
if ($method !== 'POST') api_fail('Method not allowed', 405);

$group = (int)($JSON['group_id'] ?? 0);
$items = $JSON['items'] ?? [];

if ($group <= 0)   api_fail('group_id required');
if (empty($items)) api_fail('At least one item required');

// Leader must own this group
$owns = $db->getSingle(
    "SELECT 1 FROM leader_group_assignments
     WHERE leader_user_id = ? AND group_id = ?",
    [$me['user_id'], $group], 'ii'
);
if (!$owns) api_fail('You are not assigned to this group', 403);

// ------------------------------------------------------------
// Validate items — each must carry a valid period_month
// ------------------------------------------------------------
$clean = [];
foreach ($items as $it) {
    $code   = trim($it['member_code'] ?? '');
    $amt    = (float)($it['amount'] ?? 0);
    $period = trim($it['period_month'] ?? '');

    if ($code === '' || $amt <= 0) continue;
    if (!preg_match('/^\d{4}-\d{2}$/', $period)) continue;

    $m = $db->getSingle(
        "SELECT member_code FROM members
         WHERE member_code = ? AND group_id = ? AND status = 'active'",
        [$code, $group], 'si'
    );
    if (!$m) continue;

    $clean[] = [
        'member_code'  => $code,
        'amount'       => $amt,
        'collected_at' => $it['collected_at'] ?? date('Y-m-d H:i:s'),
        'period_month' => $period,
        'note'         => $it['note'] ?? '',
    ];
}

if (empty($clean)) api_fail('No valid items after validation');

// ------------------------------------------------------------
// Duplicate guard — refuse if any (member, period) is already
// sitting in a pending/submitted batch for this leader + group
// ------------------------------------------------------------
$pendingPairs = $db->getAll(
    "SELECT ci.member_code, ci.period_month
     FROM collection_items ci
     JOIN collection_batches cb ON cb.batch_id = ci.batch_id
     WHERE cb.leader_user_id = ?
       AND cb.group_id = ?
       AND cb.status IN ('open','submitted')
       AND ci.period_month IS NOT NULL",
    [$me['user_id'], $group], 'ii'
);

$pendingSet = [];
foreach ($pendingPairs as $p) {
    $pendingSet[$p['member_code'] . '|' . $p['period_month']] = true;
}

$dupes = [];
foreach ($clean as $c) {
    $k = $c['member_code'] . '|' . $c['period_month'];
    if (isset($pendingSet[$k])) {
        $dupes[] = $c['member_code'] . ' (' . $c['period_month'] . ')';
    }
}
if (!empty($dupes)) {
    api_fail(
        'These member/period pairs already have a pending batch: ' .
        implode(', ', array_slice($dupes, 0, 5)) .
        (count($dupes) > 5 ? ' (+' . (count($dupes) - 5) . ' more)' : '')
    );
}

// ------------------------------------------------------------
// Create the batch
// ------------------------------------------------------------
$db->getConnection()->begin_transaction();
try {
    $batch_id = bin2hex(random_bytes(18));
    $qr_token = bin2hex(random_bytes(16));
    $total    = array_sum(array_column($clean, 'amount'));
    $count    = count($clean);

    // Summary period for the batch = earliest period among its items.
    // Required because collection_batches.period_month is NOT NULL.
    $periods     = array_column($clean, 'period_month');
    sort($periods);
    $batch_period = $periods[0];

    $db->execute(
        "INSERT INTO collection_batches
           (batch_id, leader_user_id, group_id, period_month,
            total_amount, member_count, status,
            qr_token, qr_expires_at, created_at)
         VALUES (?, ?, ?, ?, ?, ?, 'submitted',
                 ?, DATE_ADD(NOW(), INTERVAL 48 HOUR), NOW())",
        [$batch_id, (int)$me['user_id'], $group, $batch_period, $total, $count, $qr_token],
        'siisdis'
    );

    foreach ($clean as $it) {
        $db->execute(
            "INSERT INTO collection_items
               (batch_id, member_code, period_month, amount, collected_at, note)
             VALUES (?, ?, ?, ?, ?, ?)",
            [
                $batch_id,
                $it['member_code'],
                $it['period_month'],
                $it['amount'],
                $it['collected_at'],
                $it['note'],
            ],
            'sssdss'
        );
    }

    $db->getConnection()->commit();

    $sig = substr(hash_hmac('sha256', $batch_id . '|' . $qr_token, SECRET_KEY), 0, 16);

    log_sync($db, $me['user_id'], "POST leader/batches (created $batch_id, $count items)");

    api_out([
        'success'      => true,
        'batch_id'     => $batch_id,
        'qr_token'     => $qr_token,
        'qr_signature' => $sig,
        'total_amount' => $total,
        'member_count' => $count,
        'qr_expires_at'=> date('Y-m-d H:i:s', strtotime('+48 hours')),
        'qr_payload'   => json_encode([
            'v' => 1,
            'b' => $batch_id,
            't' => $qr_token,
            's' => $sig,
        ]),
    ]);

} catch (Throwable $e) {
    $db->getConnection()->rollback();
    api_fail($e->getMessage(), 500);
}