<?php
// admin/api_batch_confirm.php
// Refactored for strict data integrity.
// 1. Rejects batches missing or malformed item period_months.
// 2. Uses member_code as the sole numeric identifier.

require_once '../includes/config.php';
require_once '../includes/db_connection.php';
require_once '../includes/security.php';
require_once '../includes/auth.php';
require_once '../includes/user_functions.php';

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

$batch_id = (string)($payload['batch_id'] ?? '');
$received = (float)($payload['received_amount'] ?? 0);

if ($batch_id === '') {
    echo json_encode(['success' => false, 'error' => 'batch_id required']);
    exit;
}

$batch = $db->getSingle(
    "SELECT * FROM collection_batches WHERE batch_id = ? AND status IN ('submitted','open')",
    [$batch_id], 's'
);

if (!$batch) {
    echo json_encode(['success' => false, 'error' => 'Batch not found or already remitted']);
    exit;
}

$db->getConnection()->begin_transaction();
try {
    $items = $db->getAll("SELECT * FROM collection_items WHERE batch_id = ?", [$batch_id], 's');

    if (empty($items)) {
        throw new Exception("No items found for this batch.");
    }

    foreach ($items as $it) {
        // ── Generate identifiers ───────────────────────────────────────
        $uuid = sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
        $receipt = 'RCP-' . date('Ymd') . '-' . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);

        // ── STRICT PERIOD ENFORCEMENT ──────────────────────────────────
        // Reject if period_month is missing or malformed. No fallbacks.
        if (empty($it['period_month'])) {
            throw new Exception("Data integrity error: Item ID " . $it['item_id'] . " is missing a period_month.");
        }
        $item_period = (string)$it['period_month'];
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $item_period)) {
            throw new Exception("Data integrity error: Item ID " . $it['item_id'] . " has an invalid period_month ('{$item_period}'). Expected format YYYY-MM.");
        }

        // ── NUMERIC IDENTITY ONLY ──────────────────────────────────────
        $member_code = (string)$it['member_code'];
        $member_id   = (int)$member_code; // Direct cast since alphanumeric is no longer acceptable

        // ── Insert the confirmed payment ───────────────────────────────
        $db->execute(
            "INSERT INTO payments
               (payment_uuid, member_id, member_code, payment_date, period_month,
                amount, payment_method, payment_status, confirmed_by, confirmed_date,
                receipt_number, notes, created_at)
             VALUES (?, ?, ?, ?, ?, ?, 'cash', 'confirmed', ?, NOW(), ?, ?, NOW())",
            [
                $uuid,
                $member_id,
                $member_code,
                substr($it['collected_at'], 0, 10),
                $item_period,
                $it['amount'],
                $current_user['user_id'],
                $receipt,
                'Field collection via batch ' . substr($batch_id, 0, 8),
            ],
            'sisssdiss' // 9 params: s, i, s, s, s, d, i, s, s
        );

        $payment_id = $db->getConnection()->insert_id;

        // ── Link the item back to the payment row ──────────────────────
        $db->execute(
            "UPDATE collection_items SET payment_id = ? WHERE item_id = ?",
            [$payment_id, $it['item_id']], 'ii'
        );
    }

    // ── Rebuild member_balances from scratch ──────────────────────────
    $db->execute("DELETE FROM member_balances");
    $db->execute("
        INSERT INTO member_balances
            (member_code, total_paid, total_due, current_balance,
             last_payment_date, next_due_date, updated_at)
        SELECT
            m.member_code,
            COALESCE(SUM(CASE WHEN p.payment_status = 'confirmed' THEN p.amount ELSE 0 END), 0) AS total_paid,
            ((PERIOD_DIFF(DATE_FORMAT(CURDATE(), '%Y%m'), DATE_FORMAT(m.date_joined, '%Y%m')) + 1)
             * m.monthly_contribution) AS total_due,
            ((PERIOD_DIFF(DATE_FORMAT(CURDATE(), '%Y%m'), DATE_FORMAT(m.date_joined, '%Y%m')) + 1)
             * m.monthly_contribution)
            - COALESCE(SUM(CASE WHEN p.payment_status = 'confirmed' THEN p.amount ELSE 0 END), 0) AS current_balance,
            MAX(CASE WHEN p.payment_status = 'confirmed' THEN p.payment_date END) AS last_payment_date,
            NULL AS next_due_date,
            NOW()
        FROM members m
        LEFT JOIN payments p ON p.member_code = m.member_code
        GROUP BY m.member_code, m.date_joined, m.monthly_contribution
    ");

    // ── Mark the batch as remitted ─────────────────────────────────────
    $db->execute(
        "UPDATE collection_batches
         SET status = 'remitted', remitted_at = NOW(), remitted_to = ?
         WHERE batch_id = ?",
        [$current_user['user_id'], $batch_id], 'is'
    );

    $db->getConnection()->commit();

    Security::logEvent(
        'BATCH_REMITTED',
        "Remitted batch {$batch_id}: " . count($items) . " items, ₱" . number_format($received, 2)
    );

    echo json_encode([
        'success'         => true,
        'batch_id'        => $batch_id,
        'items_confirmed' => count($items),
        'total'           => (float)$batch['total_amount'],
    ]);

} catch (Throwable $e) {
    $db->getConnection()->rollback();
    error_log('Batch confirm failed: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}