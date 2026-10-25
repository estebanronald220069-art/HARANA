<?php
// api/auth/login.php
require_once __DIR__ . '/../_bootstrap.php';

$db = Database::getInstance();

$identifier = trim($JSON['phone'] ?? $JSON['username'] ?? '');
$password   = (string)($JSON['password'] ?? '');
$label      = trim($JSON['device_label'] ?? 'Harana Leader');

// ── Basic validation ────────────────────────────────────────────────────
if ($identifier === '' || $password === '') {
    api_fail('Phone/username and password required');
}

// ── Look up the user by username OR contact_number ──────────────────────
//
// We intentionally do NOT join `members` — the old admin login didn't need
// it, and this endpoint doesn't either. Leaders can be matched by username
// alone. If you later want to allow phone-number logins, the members
// lookup can be added back via a second query.
//
$user = $db->getSingle(
    "SELECT user_id, username, full_name, password, role, is_active
       FROM users
      WHERE (username = ? OR contact_number = ?)
      LIMIT 1",
    [$identifier, $identifier],
    'ss'
);

// ── Diagnostic mode (turn off before deploying!) ────────────────────────
// Set DEV_DIAGNOSTICS to true to see WHY a login fails.
$DEV_DIAGNOSTICS = true;

if (!$user) {
    if ($DEV_DIAGNOSTICS) {
        api_fail("No user found with username or contact = '{$identifier}'", 401);
    }
    api_fail('Invalid credentials', 401);
}

// ── Check the user is active ────────────────────────────────────────────
if ((int)$user['is_active'] !== 1) {
    if ($DEV_DIAGNOSTICS) {
        api_fail("User '{$user['username']}' exists but is_active = {$user['is_active']}", 401);
    }
    api_fail('Invalid credentials', 401);
}

// ── Check the role is allowed (case-insensitive) ────────────────────────
$role = strtolower((string)$user['role']);
if (!in_array($role, ['leader', 'admin'], true)) {
    if ($DEV_DIAGNOSTICS) {
        api_fail("User '{$user['username']}' has role '{$user['role']}' — only 'leader' or 'admin' can log in here.", 403);
    }
    api_fail('Invalid credentials', 401);
}

// ── Verify the password ─────────────────────────────────────────────────
$pwInfo = password_get_info($user['password']);

if ($pwInfo['algoName'] === 'unknown') {
    // The stored value is NOT a bcrypt/argon hash — likely plain text.
    if ($DEV_DIAGNOSTICS) {
        api_fail("Password for '{$user['username']}' is stored as plain text, not a hash. Fix with: UPDATE users SET password = password_hash(...) WHERE user_id = {$user['user_id']};", 500);
    }
    api_fail('Login unavailable', 500);
}

if (!password_verify($password, $user['password'])) {
    if ($DEV_DIAGNOSTICS) {
        api_fail("Wrong password for '{$user['username']}'.", 401);
    }
    api_fail('Invalid credentials', 401);
}

// ── Issue a new bearer token ────────────────────────────────────────────
$plain = bin2hex(random_bytes(32));
$hash  = hash('sha256', $plain);

$db->execute(
    "INSERT INTO api_tokens
        (user_id, token_hash, device_label, created_at, expires_at, revoked)
     VALUES (?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), 0)",
    [$user['user_id'], $hash, $label],
    'iss'
);

log_sync($db, $user['user_id'], 'auth/login');

api_out([
    'success'    => true,
    'token'      => $plain,
    'user_id'    => (int)$user['user_id'],
    'username'   => $user['username'],
    'full_name'  => $user['full_name'],
    'role'       => $user['role'],
    'expires_in' => 30 * 86400,
]);