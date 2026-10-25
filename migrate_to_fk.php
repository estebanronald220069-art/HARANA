<?php
// migrate_to_fk.php — v2 (fixed SHOW TABLES check)
// ============================================================
// Run once: http://localhost/harana_financial_system/migrate_to_fk.php
// Then DELETE this file immediately.
// ============================================================

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db_connection.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/auth.php';

$auth->requireLogin();
$current_user = $auth->getCurrentUser();
if (($current_user['role'] ?? '') !== 'admin') {
    http_response_code(403);
    die('Access denied. Admin only.');
}

$db = Database::getInstance();

header('Content-Type: text/html; charset=utf-8');
echo '<!DOCTYPE html><html><head><meta charset="utf-8">';
echo '<title>Migration: FK Enforcement</title>';
echo '<style>
  body { font-family: monospace; background: #111; color: #d0d0d0; padding: 24px; line-height: 1.5; }
  h1, h2 { color: #ffcf5c; }
  .ok   { color: #7ee787; }
  .warn { color: #ffcf5c; }
  .err  { color: #ff9c95; }
  .info { color: #79c0ff; }
  pre   { background: #1c1c1d; padding: 12px; border-radius: 6px; overflow-x: auto; }
</style></head><body>';
echo '<h1>Migration: chapter_id / group_id / leader assignments</h1>';

function say($msg, $class = 'info') {
    echo '<div class="' . $class . '">' . htmlspecialchars($msg) . '</div>';
    if (function_exists('ob_flush')) { @ob_flush(); }
    @flush();
}
function ok($msg)   { say('[ OK ]   ' . $msg, 'ok'); }
function warn($msg) { say('[ WARN ] ' . $msg, 'warn'); }
function err($msg)  { say('[ ERR ]  ' . $msg, 'err'); }
function info($msg) { say('[ INFO ] ' . $msg, 'info'); }

/* ============================================================
   SAFETY: are we already migrated? (use information_schema,
   which supports prepared statements)
   ============================================================ */
$col = $db->getSingle(
    "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'members'
       AND COLUMN_NAME  = 'chapter_id'"
);
if ($col) {
    err('ABORT: members.chapter_id already exists. Migration appears to have already run.');
    err('If you need to re-run, first restore from your backup.');
    echo '</body></html>';
    exit;
}

/* ============================================================
   SAFETY: confirm required tables exist.
   NOTE: SHOW TABLES LIKE ? cannot be prepared with a bound
   parameter, so we use a real SELECT against each table.
   ============================================================ */
$need_tables = ['members', 'chapters', 'groups', 'users'];
foreach ($need_tables as $t) {
    try {
        $db->getSingle("SELECT 1 FROM `$t` LIMIT 1");
    } catch (Throwable $e) {
        err("ABORT: required table `$t` is missing or inaccessible.");
        err("Underlying error: " . $e->getMessage());
        echo '</body></html>';
        exit;
    }
}
ok('All required tables exist.');

/* ============================================================
   STEP 1 — Add `leader` to the users.role enum
   ============================================================ */
say('');
say('=== STEP 1 — Add `leader` role ===', 'warn');
try {
    $db->execute(
        "ALTER TABLE `users`
         MODIFY COLUMN `role` ENUM('admin','treasurer','viewer','leader')
         NOT NULL DEFAULT 'viewer'"
    );
    ok("users.role now supports: admin, treasurer, viewer, leader");
} catch (Throwable $e) {
    err('Failed to modify users.role: ' . $e->getMessage());
    echo '</body></html>';
    exit;
}

/* ============================================================
   STEP 2 — Add chapter_id / group_id columns
   ============================================================ */
say('');
say('=== STEP 2 — Add chapter_id / group_id columns ===', 'warn');
try {
    $db->execute("ALTER TABLE `members` ADD COLUMN `chapter_id` INT(11) NULL AFTER `chapter`");
    ok('Added members.chapter_id');
    $db->execute("ALTER TABLE `members` ADD COLUMN `group_id` INT(11) NULL AFTER `chapter_id`");
    ok('Added members.group_id');
} catch (Throwable $e) {
    err('Failed to add columns: ' . $e->getMessage());
    echo '</body></html>';
    exit;
}

/* ============================================================
   STEP 3 — Backfill chapter_id
   ============================================================ */
say('');
say('=== STEP 3 — Backfill chapter_id ===', 'warn');

$distinct_chapters = $db->getAll(
    "SELECT DISTINCT `chapter` AS name
     FROM `members`
     WHERE `chapter` IS NOT NULL AND `chapter` <> ''
     ORDER BY `chapter`"
);

$chapter_map = [];
$created_chapters = 0;
$updated_members  = 0;

foreach ($distinct_chapters as $row) {
    $raw = (string)$row['name'];
    $normalized = mb_strtolower(trim($raw));

    $existing = $db->getSingle(
        "SELECT chapter_id, chapter_name FROM chapters
         WHERE LOWER(TRIM(chapter_name)) = ?",
        [$normalized], 's'
    );

    if ($existing) {
        $chapter_id = (int)$existing['chapter_id'];
        info("Mapped '$raw' → existing chapter_id=$chapter_id ('{$existing['chapter_name']}')");
    } else {
        $insert = $db->execute(
            "INSERT INTO chapters (chapter_name, is_active, sort_order)
             VALUES (?, 1, 0)",
            [$raw], 's'
        );
        if ($insert === false) {
            err("Could not insert chapter '$raw'");
            continue;
        }
        $chapter_id = (int)$db->getConnection()->insert_id;
        $created_chapters++;
        ok("Created new chapter_id=$chapter_id for '$raw'");
    }

    $chapter_map[$normalized] = $chapter_id;

    $affected = $db->execute(
        "UPDATE members
         SET chapter_id = ?
         WHERE LOWER(TRIM(chapter)) = ? AND chapter_id IS NULL",
        [$chapter_id, $normalized], 'is'
    );
    $updated_members += max(0, (int)$affected);
}

info("Chapters created: $created_chapters");
info("Member rows given a chapter_id: $updated_members");

/* ============================================================
   STEP 4 — Backfill group_id
   ============================================================ */
say('');
say('=== STEP 4 — Backfill group_id ===', 'warn');

$distinct_groups = $db->getAll(
    "SELECT DISTINCT chapter, group_name
     FROM members
     WHERE group_name IS NOT NULL AND group_name <> ''
       AND chapter   IS NOT NULL AND chapter    <> ''"
);

$created_groups = 0;
$updated_members_g = 0;

foreach ($distinct_groups as $row) {
    $chapter_name = (string)$row['chapter'];
    $group_name   = (string)$row['group_name'];

    $chapter_key = mb_strtolower(trim($chapter_name));
    if (!isset($chapter_map[$chapter_key])) {
        warn("Skipping group '$group_name' — chapter '$chapter_name' not in map.");
        continue;
    }
    $chapter_id = $chapter_map[$chapter_key];

    $existing = $db->getSingle(
        "SELECT group_id FROM groups
         WHERE chapter_id = ? AND LOWER(TRIM(group_name)) = ?",
        [$chapter_id, mb_strtolower(trim($group_name))], 'is'
    );

    if ($existing) {
        $group_id = (int)$existing['group_id'];
        info("Mapped '$chapter_name / $group_name' → group_id=$group_id");
    } else {
        $insert = $db->execute(
            "INSERT INTO groups (group_name, chapter_id, is_active)
             VALUES (?, ?, 1)",
            [$group_name, $chapter_id], 'si'
        );
        if ($insert === false) {
            err("Could not insert group '$group_name' under chapter_id=$chapter_id");
            continue;
        }
        $group_id = (int)$db->getConnection()->insert_id;
        $created_groups++;
        ok("Created group_id=$group_id for '$chapter_name / $group_name'");
    }

    $affected = $db->execute(
        "UPDATE members
         SET group_id = ?
         WHERE chapter_id = ?
           AND LOWER(TRIM(group_name)) = ?
           AND group_id IS NULL",
        [$group_id, $chapter_id, mb_strtolower(trim($group_name))], 'iis'
    );
    $updated_members_g += max(0, (int)$affected);
}

info("Groups created: $created_groups");
info("Member rows given a group_id: $updated_members_g");

/* ============================================================
   STEP 5 — leader accounts + assignments
   ============================================================ */
say('');
say('=== STEP 5 — Create leader accounts and assignments ===', 'warn');

$db->execute("
    CREATE TABLE IF NOT EXISTS `leader_assignments` (
      `assignment_id` INT(11) NOT NULL AUTO_INCREMENT,
      `leader_user_id` INT(11) NOT NULL,
      `group_id` INT(11) NOT NULL,
      `assigned_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `assigned_by` INT(11) NOT NULL,
      PRIMARY KEY (`assignment_id`),
      UNIQUE KEY `uq_leader_group` (`leader_user_id`, `group_id`),
      CONSTRAINT `fk_la_leader` FOREIGN KEY (`leader_user_id`) REFERENCES `users`(`user_id`) ON DELETE CASCADE,
      CONSTRAINT `fk_la_group`  FOREIGN KEY (`group_id`)       REFERENCES `groups`(`group_id`) ON DELETE CASCADE,
      CONSTRAINT `fk_la_assigned_by` FOREIGN KEY (`assigned_by`) REFERENCES `users`(`user_id`) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
ok('leader_assignments table ready.');

$distinct_leaders = $db->getAll(
    "SELECT DISTINCT leader
     FROM members
     WHERE leader IS NOT NULL AND leader <> ''"
);

$created_leaders = 0;
$assignments_created = 0;

foreach ($distinct_leaders as $row) {
    $leader_name = trim((string)$row['leader']);
    if ($leader_name === '') continue;

    $user = $db->getSingle(
        "SELECT user_id, username FROM users
         WHERE LOWER(TRIM(full_name)) = ? OR LOWER(TRIM(username)) = ?",
        [mb_strtolower($leader_name), mb_strtolower($leader_name)], 'ss'
    );

    if ($user) {
        $leader_user_id = (int)$user['user_id'];
        info("Leader '$leader_name' → existing user_id=$leader_user_id ({$user['username']})");
    } else {
        $base = preg_replace('/[^a-z0-9]+/', '_', mb_strtolower($leader_name));
        $base = trim($base, '_');
        if ($base === '') $base = 'leader';

        $username = $base;
        $suffix = 1;
        while ($db->getSingle("SELECT user_id FROM users WHERE username = ?", [$username], 's')) {
            $suffix++;
            $username = $base . '_' . $suffix;
        }

        $temp_password = bin2hex(random_bytes(4));
        $hash = password_hash($temp_password, PASSWORD_DEFAULT);

        $insert = $db->execute(
            "INSERT INTO users (username, password, full_name, role, is_active, created_at)
             VALUES (?, ?, ?, 'leader', 1, NOW())",
            [$username, $hash, $leader_name], 'sss'
        );
        if ($insert === false) {
            err("Could not create user for leader '$leader_name'");
            continue;
        }
        $leader_user_id = (int)$db->getConnection()->insert_id;
        $created_leaders++;
        ok("Created leader user_id=$leader_user_id, username='$username', temp password='$temp_password'");
        warn("   ^ Save that password! Admin must change it after first login.");
    }

    $groups_led = $db->getAll(
        "SELECT DISTINCT group_id FROM members
         WHERE LOWER(TRIM(leader)) = ? AND group_id IS NOT NULL",
        [mb_strtolower($leader_name)], 's'
    );

    foreach ($groups_led as $g) {
        $gid = (int)$g['group_id'];
        $existing = $db->getSingle(
            "SELECT assignment_id FROM leader_assignments
             WHERE leader_user_id = ? AND group_id = ?",
            [$leader_user_id, $gid], 'ii'
        );
        if ($existing) {
            info("Assignment already exists: leader_id=$leader_user_id ↔ group_id=$gid");
            continue;
        }
        $ok2 = $db->execute(
            "INSERT INTO leader_assignments (leader_user_id, group_id, assigned_by)
             VALUES (?, ?, ?)",
            [$leader_user_id, $gid, $current_user['user_id']], 'iii'
        );
        if ($ok2) {
            $assignments_created++;
            ok("Assigned leader_id=$leader_user_id to group_id=$gid");
        } else {
            err("Failed to assign leader_id=$leader_user_id to group_id=$gid");
        }
    }
}

info("Leader accounts created: $created_leaders");
info("Leader assignments created: $assignments_created");

/* ============================================================
   STEP 6 — Add foreign keys on members
   ============================================================ */
say('');
say('=== STEP 6 — Add foreign key constraints ===', 'warn');

try {
    $db->execute(
        "ALTER TABLE `members`
         ADD CONSTRAINT `fk_members_chapter`
         FOREIGN KEY (`chapter_id`) REFERENCES `chapters`(`chapter_id`)
         ON DELETE SET NULL"
    );
    ok('FK added: members.chapter_id → chapters.chapter_id');
} catch (Throwable $e) {
    warn('Skipped chapter FK: ' . $e->getMessage());
}

try {
    $db->execute(
        "ALTER TABLE `members`
         ADD CONSTRAINT `fk_members_group`
         FOREIGN KEY (`group_id`) REFERENCES `groups`(`group_id`)
         ON DELETE SET NULL"
    );
    ok('FK added: members.group_id → groups.group_id');
} catch (Throwable $e) {
    warn('Skipped group FK: ' . $e->getMessage());
}

/* ============================================================
   STEP 7 — legacy columns NOT dropped automatically
   ============================================================ */
say('');
say('=== STEP 7 — Legacy string columns (NOT dropped) ===', 'warn');
say('');
say('The old `members.chapter`, `members.group_name`, and `members.leader`', 'info');
say('columns were LEFT INTACT for safety. Verify the migration worked, then', 'info');
say('manually run the following SQL in phpMyAdmin when you are confident:', 'info');
echo '<pre>-- Run ONLY after you confirm everything works:
ALTER TABLE `members` DROP COLUMN `chapter`;
ALTER TABLE `members` DROP COLUMN `group_name`;
ALTER TABLE `members` DROP COLUMN `leader`;</pre>';

/* ============================================================
   STEP 8 — audit log
   ============================================================ */
try {
    Security::logEvent(
        'MIGRATION_FK',
        "Migrated chapter/group/leader to FK. " .
        "Chapters created: $created_chapters, " .
        "Groups created: $created_groups, " .
        "Leaders created: $created_leaders, " .
        "Assignments created: $assignments_created."
    );
    ok('Audit log entry written.');
} catch (Throwable $e) {
    warn('Could not write audit log: ' . $e->getMessage());
}

say('');
say('=== DONE ===', 'ok');
say('Next steps:', 'info');
say('1. Spot-check a few member rows in phpMyAdmin to confirm chapter_id/group_id are filled.');
say('2. Log in as one of the new leader accounts to confirm the account exists.');
say('3. When confident, drop the legacy string columns (SQL shown above).');
say('4. DELETE this file (migrate_to_fk.php) from the server.');

echo '</body></html>';