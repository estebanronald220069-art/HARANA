<?php
// api/logout.php
require_once __DIR__ . '/../_bootstrap.php';

$db = Database::getInstance();
$me = require_bearer($db);

// ── Extract the raw token (strip "Bearer " prefix) ──────────────────────
$hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if ($hdr === '' && function_exists('getallheaders')) {
    $h = getallheaders();
    $hdr = $h['Authorization'] ?? $h['authorization'] ?? '';
}

$rawToken = '';
if (preg_match('/Bearer\s+(\S+)/i', $hdr, $m)) {
    $rawToken = $m[1];
}

// ── Revoke the token row ────────────────────────────────────────────────
if ($rawToken !== '') {
    $db->execute(
        "UPDATE api_tokens
            SET revoked = 1,
                revoked_at = NOW()
          WHERE user_id = ?
            AND token_hash = SHA2(?, 256)",
        [$me['user_id'], $rawToken],
        'is'
    );
}

log_sync($db, $me['user_id'], 'auth/logout');

api_out(['success' => true]);