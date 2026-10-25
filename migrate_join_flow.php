<?php
// migrate_join_flow.php — one-time migration. DELETE AFTER USE.
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db_connection.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/auth.php';

$auth->requireLogin();
$cu = $auth->getCurrentUser();
if (($cu['role'] ?? '') !== 'admin') { http_response_code(403); die('Admin only.'); }

$db = Database::getInstance();
header('Content-Type: text/html; charset=utf-8');
echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Migration: Join Flow</title>';
echo '<style>body{font-family:monospace;background:#111;color:#d0d0d0;padding:24px;line-height:1.5}
h1,h2{color:#ffcf5c}.ok{color:#7ee787}.warn{color:#ffcf5c}.err{color:#ff9c95}.info{color:#79c0ff}
pre{background:#1c1c1d;padding:12px;border-radius:6px}</style></head><body>';
echo '<h1>Migration: Two-Step Join Flow</h1>';

function say($m,$c='info'){echo "<div class=\"$c\">".htmlspecialchars($m)."</div>";}
function ok($m){say('[ OK ]   '.$m,'ok');}
function warn($m){say('[ WARN ] '.$m,'warn');}
function err($m){say('[ ERR ]  '.$m,'err');}
function info($m){say('[ INFO ] '.$m,'info');}

// ---- Safety: abort if already migrated ----
$col = $db->getSingle(
    "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'account_status'"
);
if ($col) {
    err('ABORT: users.account_status already exists. Migration already run.');
    echo '</body></html>'; exit;
}

// ---- 1. Add account_status ----
try {
    $db->execute("ALTER TABLE `users` ADD COLUMN `account_status` 
                  ENUM('joined','pending_approval','approved','rejected') 
                  NOT NULL DEFAULT 'joined' AFTER `role`");
    ok('Added users.account_status');
} catch (Throwable $e) { err('Failed: '.$e->getMessage()); echo '</body></html>'; exit; }

// ---- 2. Add email verification columns ----
try {
    $db->execute("ALTER TABLE `users` ADD COLUMN `email_verified_at` DATETIME NULL AFTER `account_status`");
    $db->execute("ALTER TABLE `users` ADD COLUMN `email_verify_token` VARCHAR(64) NULL AFTER `email_verified_at`");
    ok('Added email_verified_at + email_verify_token');
} catch (Throwable $e) { err('Failed: '.$e->getMessage()); echo '</body></html>'; exit; }

// ---- 3. Backfill: admins & existing members are approved ----
try {
    $n = $db->execute("UPDATE `users` SET `account_status` = 'approved' WHERE `role` = 'admin'");
    info("Admins set to approved: $n");

    $n = $db->execute("UPDATE `users` u 
                       INNER JOIN `members` m ON m.user_id = u.user_id 
                       SET u.`account_status` = 'approved'");
    info("Existing members set to approved: $n");

    // Anyone with role treasurer/viewer/leader but no member record → approved for safety
    $n = $db->execute("UPDATE `users` SET `account_status` = 'approved' 
                       WHERE `role` IN ('treasurer','leader','viewer') 
                         AND `user_id` NOT IN (SELECT COALESCE(user_id, 0) FROM `members`)");
    info("Existing staff accounts set to approved: $n");

    // Set email_verified_at for all existing accounts (trusted)
    $db->execute("UPDATE `users` SET `email_verified_at` = COALESCE(`created_at`, NOW()) WHERE `email_verified_at` IS NULL");
    ok('Existing accounts marked as email-verified');
} catch (Throwable $e) { err('Backfill failed: '.$e->getMessage()); }

// ---- 4. Add user_id to pending_users ----
$col = $db->getSingle(
    "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pending_users' AND COLUMN_NAME = 'user_id'"
);
if (!$col) {
    try {
        $db->execute("ALTER TABLE `pending_users` ADD COLUMN `user_id` INT(11) NULL AFTER `id`");
        $db->execute("ALTER TABLE `pending_users` ADD INDEX `idx_pu_user` (`user_id`)");
        try {
            $db->execute("ALTER TABLE `pending_users` 
                          ADD CONSTRAINT `fk_pu_user` FOREIGN KEY (`user_id`) 
                          REFERENCES `users`(`user_id`) ON DELETE SET NULL");
            ok('Added pending_users.user_id + FK');
        } catch (Throwable $e) {
            warn('FK failed (index created anyway): '.$e->getMessage());
        }
    } catch (Throwable $e) { err('Add column failed: '.$e->getMessage()); }
} else {
    info('pending_users.user_id already exists');
}

// ---- 5. Add index on users.account_status ----
try {
    $db->execute("ALTER TABLE `users` ADD INDEX `idx_account_status` (`account_status`)");
    ok('Added index on users.account_status');
} catch (Throwable $e) { warn('Index: '.$e->getMessage()); }

// ---- 6. Audit ----
try {
    Security::logEvent('MIGRATION_JOIN_FLOW', 'Two-step join flow migration applied.');
    ok('Audit logged');
} catch (Throwable $e) { warn('Audit: '.$e->getMessage()); }

say('');
say('=== DONE ===','ok');
say('Next steps:','info');
say('1. Delete this file (migrate_join_flow.php) immediately.');
say('2. Deploy the new files below.');
say('3. Clear your browser session / log out and back in.');
echo '</body></html>';