<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db_connection.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/auth.php';

// ============ CORS ============
$origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
header('Access-Control-Allow-Origin: ' . $origin);
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Max-Age: 86400');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}
// ==============================

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$RAW = file_get_contents('php://input');
$JSON = json_decode($RAW, true) ?: [];

function api_out($data, int $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function api_fail(string $msg, int $code = 400) {
    api_out(['success' => false, 'error' => $msg], $code);
}

function require_bearer($db) {
    $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if ($hdr === '' && function_exists('getallheaders')) {
        $h = getallheaders();
        $hdr = $h['Authorization'] ?? '';
    }
    if (!preg_match('/Bearer\s+(\S+)/i', $hdr, $m)) {
        api_fail('Missing bearer token', 401);
    }
    $hash = hash('sha256', $m[1]);

    $row = $db->getSingle(
        "SELECT t.token_id, u.user_id, u.username, u.full_name, u.role
         FROM api_tokens t
         JOIN users u ON u.user_id = t.user_id
         WHERE t.token_hash = ?
           AND t.revoked = 0
           AND (t.expires_at IS NULL OR t.expires_at > NOW())
           AND u.is_active = 1",
        [$hash], 's'
    );
    if (!$row) api_fail('Invalid or expired token', 401);

    $db->execute("UPDATE api_tokens SET last_used_at = NOW() WHERE token_id = ?",
                 [$row['token_id']], 'i');

    return $row;
}

function log_sync($db, $user_id, $endpoint) {
    $uuid = bin2hex(random_bytes(18));
    try {
        $db->execute(
            "INSERT INTO sync_requests (request_id, user_id, endpoint) VALUES (?, ?, ?)",
            [$uuid, $user_id, $endpoint], 'sis'
        );
    } catch (Throwable $e) { /* silent */ }
}