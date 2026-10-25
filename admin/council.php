<?php
// admin/council.php - Council CRUD (edited tab filters NULL rows, delete back)
require_once '../includes/config.php';
require_once '../includes/db_connection.php';
require_once '../includes/security.php';
require_once '../includes/auth.php';

$auth->requireLogin();
$current_user = $auth->getCurrentUser();
$db = Database::getInstance();
$message = '';
$error   = '';

/* ============================================================
   HELPERS
   ============================================================ */
function bindTypes(array $params): string {
    $t = '';
    foreach ($params as $p) {
        if (is_int($p))        $t .= 'i';
        elseif (is_float($p))  $t .= 'd';
        else                   $t .= 's';
    }
    return $t;
}

function dbRun($db, string $sql, array $params = [], ?string $types = null) {
    if ($types === null) $types = bindTypes($params);
    $ok = $db->execute($sql, $params, $types);
    if ($ok === false) {
        $conn = $db->getConnection();
        throw new Exception(($conn->error ?: 'unknown SQL error') . ' | ' . $sql);
    }
    return $ok;
}

function safeCount($db, string $sql): int {
    try {
        $r = $db->getSingle($sql, [], '');
        return (int)($r['t'] ?? $r['cnt'] ?? $r['total'] ?? 0);
    } catch (Throwable $e) {
        return 0;
    }
}

function ensureColumns($db, string $table, array $colDefs): array {
    try {
        $existing = array_column($db->getAll("SHOW COLUMNS FROM `$table`"), 'Field');
    } catch (Throwable $e) {
        return ['ok' => false, 'added' => [], 'error' => "Table $table not found: " . $e->getMessage()];
    }
    $added = [];
    foreach ($colDefs as $col => $def) {
        if (!in_array($col, $existing, true)) {
            try {
                $db->execute("ALTER TABLE `$table` ADD COLUMN `$col` $def");
                $added[] = $col;
            } catch (Throwable $e) {
                return ['ok' => false, 'added' => $added, 'error' => "Failed adding $table.$col: " . $e->getMessage()];
            }
        }
    }
    return ['ok' => true, 'added' => $added, 'error' => ''];
}

/* ============================================================
   AUTO-MIGRATE MISSING COLUMNS
   ============================================================ */
$migration_errors = [];

$r = ensureColumns($db, 'council_deleted', [
    'original_id'            => 'INT NULL',
    'recovery_deadline'      => 'DATETIME NULL',
    'full_name'              => 'VARCHAR(255) NULL',
    'position'               => 'VARCHAR(100) NULL',
    'contact_number'         => 'VARCHAR(50) NULL',
    'email'                  => 'VARCHAR(255) NULL',
    'status'                 => 'VARCHAR(20) NULL',
    'permanently_deleted_at' => 'DATETIME NULL',
]);
if (!$r['ok']) $migration_errors[] = $r['error'];

$r = ensureColumns($db, 'council_edit_history', [
    'changes'        => 'LONGTEXT NULL',
    'edited_by'      => 'INT NULL',
    'edited_by_name' => 'VARCHAR(100) NULL',
    'edited_at'      => 'DATETIME NULL',
    'is_reverted'    => 'TINYINT(1) NOT NULL DEFAULT 0',
    'reverted_at'    => 'DATETIME NULL',
    'reverted_by'    => 'INT NULL',
]);
if (!$r['ok']) $migration_errors[] = $r['error'];

if (!empty($migration_errors)) {
    $error = 'Schema migration warning: ' . implode(' | ', $migration_errors);
}

/* ============================================================
   LEGACY ROW CLEANUP
   ============================================================ */
try {
    $db->execute(
        "UPDATE council_edit_history
         SET is_reverted = 1, reverted_at = NOW()
         WHERE is_reverted = 0
           AND (changes IS NULL OR changes = '' OR changes = 'null' OR changes = '[]')"
    );
} catch (Throwable $e) { /* silent */ }

/* ============================================================
   AJAX: get edit-history details
   ============================================================ */
if (isset($_GET['get_edit_details'], $_GET['history_id'])) {
    header('Content-Type: application/json');
    try {
        $hid = (int)$_GET['history_id'];
        $log = $db->getSingle("SELECT * FROM council_edit_history WHERE history_id=?", [$hid], 'i');
        if ($log) {
            echo json_encode([
                'success' => true,
                'changes' => json_decode($log['changes'] ?? '[]', true),
                'log'     => $log,
            ]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Not found']);
        }
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

/* ============================================================
   FLASH MESSAGES
   ============================================================ */
$success_toast = '';
if (isset($_GET['success'])) {
    $map = [
        'added'               => 'Council member added successfully.',
        'updated'             => 'Council member updated successfully.',
        'inactivated'         => 'Council member moved to Inactive tab.',
        'activated'           => 'Council member moved to Active tab.',
        'deleted'             => 'Council member moved to Deleted tab.',
        'permanently_deleted' => 'Council member permanently deleted.',
        'recovered'           => 'Council member recovered — check the Active or Inactive tab.',
        'undo_edit'           => 'Edit reverted — member restored to its prior values.',
    ];
    $success_toast = $map[$_GET['success']] ?? '';
}

/* ============================================================
   SIDEBAR BADGE
   ============================================================ */
$pending_count = 0;
if ($current_user['role'] === 'admin') {
    try {
        $pending_count = (int)($db->getSingle("SELECT COUNT(*) cnt FROM pending_users WHERE status='pending'")['cnt'] ?? 0);
    } catch (Throwable $e) { $pending_count = 0; }
}

/* ============================================================
   TABS
   ============================================================ */
$active_tab = $_GET['tab'] ?? 'active';
if (!in_array($active_tab, ['active','inactive','edited','deleted'], true)) $active_tab = 'active';

$active_count   = safeCount($db, "SELECT COUNT(*) t FROM council WHERE status='active'   AND is_deleted=0");
$inactive_count = safeCount($db, "SELECT COUNT(*) t FROM council WHERE status='inactive' AND is_deleted=0");
$edited_count   = safeCount($db,
    "SELECT COUNT(*) t FROM council_edit_history
     WHERE is_reverted=0
       AND changes IS NOT NULL AND changes != '' AND changes != 'null' AND changes != '[]'");
$deleted_count  = safeCount($db, "SELECT COUNT(*) t FROM council_deleted WHERE is_permanently_deleted=0");

/* ============================================================
   PAGINATION + PER-TAB SEARCH
   ============================================================ */
$per_page = 15;
$page     = max(1, (int)($_GET['page'] ?? 1));
$offset   = ($page - 1) * $per_page;
$search   = Security::sanitize($_GET['search'] ?? '');

$search_condition = '';
$search_params    = [];

if ($search !== '') {
    $like = "%$search%";
    if ($active_tab === 'deleted') {
        $search_condition = " AND (
            JSON_UNQUOTE(JSON_EXTRACT(cd.original_data,'$.full_name'))      LIKE ?
         OR JSON_UNQUOTE(JSON_EXTRACT(cd.original_data,'$.position'))       LIKE ?
         OR JSON_UNQUOTE(JSON_EXTRACT(cd.original_data,'$.email'))          LIKE ?
         OR JSON_UNQUOTE(JSON_EXTRACT(cd.original_data,'$.contact_number')) LIKE ?
        )";
        $search_params = [$like, $like, $like, $like];
    } elseif ($active_tab === 'edited') {
        $search_condition = " AND (c.full_name LIKE ? OR c.position LIKE ? OR h.edited_by_name LIKE ?)";
        $search_params = [$like, $like, $like];
    } else {
        $search_condition = " AND (c.full_name LIKE ? OR c.position LIKE ? OR c.email LIKE ? OR c.contact_number LIKE ?)";
        $search_params = [$like, $like, $like, $like];
    }
}

/* ============================================================
   FETCH ROWS BY TAB
   ============================================================ */
$rows = [];
$total_records = 0;

$valid_history_sql = " AND h.changes IS NOT NULL AND h.changes != '' AND h.changes != 'null' AND h.changes != '[]' ";

try {
    if ($active_tab === 'active' || $active_tab === 'inactive') {
        $status = $active_tab;
        $cp = array_merge([$status], $search_params);
        $total_records = (int)($db->getSingle(
            "SELECT COUNT(*) t FROM council c WHERE c.status=? AND c.is_deleted=0 $search_condition",
            $cp, bindTypes($cp)
        )['t'] ?? 0);

        $qp = array_merge([$status], $search_params, [$per_page, $offset]);
        $rows = $db->getAll(
            "SELECT c.* FROM council c
             WHERE c.status=? AND c.is_deleted=0 $search_condition
             ORDER BY c.created_at DESC LIMIT ? OFFSET ?",
            $qp, bindTypes($qp)
        );
    }
    elseif ($active_tab === 'edited') {
        $cp = $search_params;
        $total_records = (int)($db->getSingle(
            "SELECT COUNT(*) t FROM council_edit_history h
             LEFT JOIN council c ON c.council_id = h.council_id
             WHERE h.is_reverted=0 $valid_history_sql $search_condition",
            $cp, bindTypes($cp)
        )['t'] ?? 0);

        $qp = array_merge($search_params, [$per_page, $offset]);
        $rows = $db->getAll(
            "SELECT h.history_id, h.council_id, h.changes, h.edited_at,
                    h.edited_by_name, h.reverted_at, h.is_reverted,
                    c.full_name, c.position, c.photo, c.status
             FROM council_edit_history h
             LEFT JOIN council c ON c.council_id = h.council_id
             WHERE h.is_reverted=0 $valid_history_sql $search_condition
             ORDER BY h.edited_at DESC LIMIT ? OFFSET ?",
            $qp, bindTypes($qp)
        );
    }
    elseif ($active_tab === 'deleted') {
        $total_records = (int)($db->getSingle(
            "SELECT COUNT(*) t FROM council_deleted cd
             WHERE cd.is_permanently_deleted=0 $search_condition",
            $search_params, bindTypes($search_params)
        )['t'] ?? 0);

        $qp = array_merge($search_params, [$per_page, $offset]);
        $rows = $db->getAll(
            "SELECT cd.* FROM council_deleted cd
             WHERE cd.is_permanently_deleted=0 $search_condition
             ORDER BY cd.deleted_at DESC LIMIT ? OFFSET ?",
            $qp, bindTypes($qp)
        );
    }
} catch (Throwable $e) {
    $error = 'Query error: ' . $e->getMessage();
    $rows = [];
}

$total_pages = $per_page > 0 ? max(1, (int)ceil($total_records / $per_page)) : 1;
if ($page > $total_pages) $page = $total_pages;

/* ============================================================
   POSITIONS
   ============================================================ */
$positions = [
    'CEO/President','COO/Vice President','CFO/Treasurer','Book Keeper',
    'Corporate Secretary','Supply Officer','Internal Auditor',
    'Monitoring Officer I','Monitoring Officer II',
    'Liaison/Office Secretary','Encoder','Over-all Adviser',
];

/* ============================================================
   POST HANDLERS
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please refresh the page.';
    } else {
        $action = $_POST['action'] ?? '';

        /* ---------------- ADD ---------------- */
        if ($action === 'add') {
            $last_name   = Security::sanitize($_POST['last_name']   ?? '');
            $first_name  = Security::sanitize($_POST['first_name']  ?? '');
            $middle_name = Security::sanitize($_POST['middle_name'] ?? '');
            $position    = Security::sanitize($_POST['position']    ?? '');
            $contact     = Security::sanitize($_POST['contact_number'] ?? '');
            $email       = Security::sanitize($_POST['email']       ?? '');
            $term_start  = !empty($_POST['term_start']) ? $_POST['term_start'] : null;
            $term_end    = !empty($_POST['term_end'])   ? $_POST['term_end']   : null;
            $full_name   = trim($last_name . ', ' . $first_name . ' ' . $middle_name);

            if ($last_name === '' || $first_name === '' || $position === '') {
                $error = 'Please fill all required fields.';
            } else {
                try {
                    dbRun($db,
                        "INSERT INTO council
                         (first_name,last_name,middle_name,full_name,position,
                          contact_number,email,term_start,term_end,status,
                          is_deleted,created_at,created_by)
                         VALUES (?,?,?,?,?,?,?,?,?,'active',0,NOW(),?)",
                        [$first_name,$last_name,$middle_name,$full_name,$position,
                         $contact,$email,$term_start,$term_end,$current_user['user_id']]
                    );
                    header('Location: council.php?tab=active&success=added');
                    exit();
                } catch (Throwable $e) {
                    $error = 'Add failed: ' . $e->getMessage();
                }
            }
        }

        /* ---------------- EDIT ---------------- */
        elseif ($action === 'edit') {
            $council_id  = (int)($_POST['council_id'] ?? 0);
            $last_name   = Security::sanitize($_POST['last_name']   ?? '');
            $first_name  = Security::sanitize($_POST['first_name']  ?? '');
            $middle_name = Security::sanitize($_POST['middle_name'] ?? '');
            $position    = Security::sanitize($_POST['position']    ?? '');
            $contact     = Security::sanitize($_POST['contact_number'] ?? '');
            $email       = Security::sanitize($_POST['email']       ?? '');
            $term_start  = !empty($_POST['term_start']) ? $_POST['term_start'] : null;
            $term_end    = !empty($_POST['term_end'])   ? $_POST['term_end']   : null;
            $status      = in_array($_POST['status'] ?? 'active', ['active','inactive'], true)
                            ? $_POST['status'] : 'active';
            $full_name   = trim($last_name . ', ' . $first_name . ' ' . $middle_name);

            try {
                $old = $db->getSingle("SELECT * FROM council WHERE council_id=?", [$council_id], 'i');
                if (!$old) {
                    $error = 'Council member not found.';
                } elseif ($last_name === '' || $first_name === '' || $position === '') {
                    $error = 'Please fill all required fields.';
                } else {
                    dbRun($db,
                        "UPDATE council SET
                            first_name=?, last_name=?, middle_name=?, full_name=?,
                            position=?, contact_number=?, email=?,
                            term_start=?, term_end=?, status=?,
                            updated_at=NOW(), updated_by=?
                         WHERE council_id=?",
                        [$first_name,$last_name,$middle_name,$full_name,
                         $position,$contact,$email,$term_start,$term_end,$status,
                         $current_user['user_id'],$council_id]
                    );

                    $tracked  = ['last_name','first_name','middle_name','full_name','position',
                                 'contact_number','email','term_start','term_end','status'];
                    $new_vals = [
                        'last_name'=>$last_name,'first_name'=>$first_name,'middle_name'=>$middle_name,
                        'full_name'=>$full_name,'position'=>$position,'contact_number'=>$contact,
                        'email'=>$email,'term_start'=>$term_start,'term_end'=>$term_end,
                        'status'=>$status,
                    ];
                    $changes = [];
                    foreach ($tracked as $f) {
                        $ov = (string)($old[$f] ?? '');
                        $nv = (string)($new_vals[$f] ?? '');
                        if ($ov !== $nv) $changes[$f] = ['old'=>$old[$f] ?? '', 'new'=>$new_vals[$f] ?? ''];
                    }

                    if (!empty($changes)) {
                        dbRun($db,
                            "INSERT INTO council_edit_history
                             (council_id, changes, edited_by, edited_by_name, edited_at, is_reverted)
                             VALUES (?,?,?,?,NOW(),0)",
                            [$council_id, json_encode($changes),
                             $current_user['user_id'], $current_user['username']]
                        );
                    }

                    header('Location: council.php?tab=active&success=updated');
                    exit();
                }
            } catch (Throwable $e) {
                $error = 'Edit failed: ' . $e->getMessage();
            }
        }

        /* ---------------- SET STATUS ---------------- */
        elseif ($action === 'set_status') {
            $council_id = (int)($_POST['council_id'] ?? 0);
            $new_status = $_POST['new_status'] ?? '';
            if (!in_array($new_status, ['active','inactive'], true)) {
                $error = 'Invalid status.';
            } else {
                try {
                    $old = $db->getSingle("SELECT status FROM council WHERE council_id=?", [$council_id], 'i');
                    if (!$old) {
                        $error = 'Council member not found.';
                    } else {
                        dbRun($db,
                            "UPDATE council SET status=?, updated_at=NOW(), updated_by=? WHERE council_id=?",
                            [$new_status, $current_user['user_id'], $council_id]
                        );

                        if ((string)$old['status'] !== (string)$new_status) {
                            $changes = ['status' => ['old'=>$old['status'], 'new'=>$new_status]];
                            dbRun($db,
                                "INSERT INTO council_edit_history
                                 (council_id, changes, edited_by, edited_by_name, edited_at, is_reverted)
                                 VALUES (?,?,?,?,NOW(),0)",
                                [$council_id, json_encode($changes),
                                 $current_user['user_id'], $current_user['username']]
                            );
                        }

                        $flash = $new_status === 'inactive' ? 'inactivated' : 'activated';
                        header("Location: council.php?tab=$new_status&success=$flash");
                        exit();
                    }
                } catch (Throwable $e) {
                    $error = 'Status change failed: ' . $e->getMessage();
                }
            }
        }

        /* ---------------- SOFT DELETE ---------------- */
        elseif ($action === 'delete') {
            $council_id = (int)($_POST['council_id'] ?? 0);
            $council = $db->getSingle("SELECT * FROM council WHERE council_id=? AND is_deleted=0", [$council_id], 'i');
            if (!$council) {
                $error = 'Council member not found.';
            } else {
                $db->getConnection()->begin_transaction();
                try {
                    dbRun($db,
                        "INSERT INTO council_deleted
                         (original_id, original_data, deleted_by, deleted_by_name,
                          deleted_at, recovery_deadline,
                          full_name, position, contact_number, email, status,
                          is_permanently_deleted, permanently_deleted_at)
                         VALUES (?,?,?,?,NOW(),?,?,?,?,?,?,0,NULL)",
                        [$council_id, json_encode($council),
                         $current_user['user_id'], $current_user['username'],
                         date('Y-m-d H:i:s', strtotime('+50 days')),
                         $council['full_name']      ?? '',
                         $council['position']       ?? '',
                         $council['contact_number'] ?? '',
                         $council['email']          ?? '',
                         $council['status']         ?? '']
                    );
                    dbRun($db, "UPDATE council SET is_deleted=1 WHERE council_id=?", [$council_id]);
                    $db->getConnection()->commit();
                    header('Location: council.php?tab=deleted&success=deleted');
                    exit();
                } catch (Throwable $e) {
                    $db->getConnection()->rollback();
                    $error = 'Delete failed: ' . $e->getMessage();
                }
            }
        }

        /* ---------------- RECOVER ---------------- */
        elseif ($action === 'recover') {
            $delete_id = (int)($_POST['delete_id'] ?? 0);
            $db->getConnection()->begin_transaction();
            try {
                $rec = $db->getSingle(
                    "SELECT * FROM council_deleted WHERE delete_id=? AND is_permanently_deleted=0",
                    [$delete_id], 'i'
                );
                if (!$rec) throw new Exception('Deleted record not found.');

                $council_id = 0;
                if (!empty($rec['original_id'])) $council_id = (int)$rec['original_id'];
                if (!$council_id && !empty($rec['original_data'])) {
                    $snap = json_decode($rec['original_data'], true);
                    if (is_array($snap) && !empty($snap['council_id'])) $council_id = (int)$snap['council_id'];
                }
                if (!$council_id) throw new Exception('Cannot determine council_id from deleted record.');

                $existing = $db->getSingle("SELECT council_id, status FROM council WHERE council_id=?", [$council_id], 'i');
                if (!$existing) throw new Exception("Council row #$council_id no longer exists.");

                dbRun($db, "UPDATE council SET is_deleted=0 WHERE council_id=?", [$council_id]);
                dbRun($db, "DELETE FROM council_deleted WHERE delete_id=?", [$delete_id]);

                $db->getConnection()->commit();
                $tab = ($existing['status'] === 'inactive') ? 'inactive' : 'active';
                header("Location: council.php?tab=$tab&success=recovered");
                exit();
            } catch (Throwable $e) {
                $db->getConnection()->rollback();
                $error = 'Recover failed: ' . $e->getMessage();
            }
        }

        /* ---------------- PERMANENT DELETE ---------------- */
        elseif ($action === 'permanent_delete') {
            $delete_id = (int)($_POST['delete_id'] ?? 0);
            try {
                $cols = array_column($db->getAll("SHOW COLUMNS FROM `council_deleted`"), 'Field');
                if (in_array('permanently_deleted_at', $cols, true)) {
                    dbRun($db,
                        "UPDATE council_deleted
                         SET is_permanently_deleted=1, permanently_deleted_at=NOW()
                         WHERE delete_id=?",
                        [$delete_id]
                    );
                } else {
                    dbRun($db, "UPDATE council_deleted SET is_permanently_deleted=1 WHERE delete_id=?", [$delete_id]);
                }
                header('Location: council.php?tab=deleted&success=permanently_deleted');
                exit();
            } catch (Throwable $e) {
                $error = 'Permanent delete failed: ' . $e->getMessage();
            }
        }

        /* ---------------- UNDO EDIT ---------------- */
        elseif ($action === 'undo_edit') {
            $council_id = (int)($_POST['council_id'] ?? 0);
            $history_id = (int)($_POST['history_id'] ?? 0);
            $db->getConnection()->begin_transaction();
            try {
                $log = $db->getSingle(
                    "SELECT * FROM council_edit_history WHERE history_id=? AND council_id=? AND is_reverted=0",
                    [$history_id, $council_id], 'ii'
                );
                if (!$log) throw new Exception('Edit history not found or already reverted.');

                $raw = $log['changes'] ?? null;
                if ($raw === null || $raw === '' || $raw === 'null' || $raw === '[]') {
                    dbRun($db,
                        "UPDATE council_edit_history
                         SET is_reverted=1, reverted_at=NOW(), reverted_by=?
                         WHERE history_id=?",
                        [$current_user['user_id'], $history_id]
                    );
                    $db->getConnection()->commit();
                    header('Location: council.php?tab=edited&success=undo_edit');
                    exit();
                }

                $changes = json_decode($raw, true);
                if (!is_array($changes) || empty($changes)) {
                    throw new Exception('Invalid change data in history row: '
                        . (is_string($raw) ? substr($raw, 0, 120) : gettype($raw)));
                }

                $allowed = ['last_name','first_name','middle_name','full_name','position',
                            'contact_number','email','term_start','term_end','status'];

                $set = $vals = [];
                foreach ($changes as $field => $pair) {
                    if (!in_array($field, $allowed, true)) continue;
                    if (!array_key_exists('old', $pair))    continue;
                    $set[]  = "`$field` = ?";
                    $vals[] = $pair['old'];
                }
                if (empty($set)) throw new Exception('No valid fields to revert.');

                $existing = $db->getSingle("SELECT council_id FROM council WHERE council_id=?", [$council_id], 'i');
                if (!$existing) throw new Exception("Council row #$council_id no longer exists.");

                $vals[] = $current_user['user_id'];
                $vals[] = $council_id;
                dbRun($db,
                    "UPDATE council SET " . implode(', ', $set) .
                    ", updated_at=NOW(), updated_by=? WHERE council_id=?",
                    $vals
                );

                dbRun($db,
                    "UPDATE council_edit_history
                     SET is_reverted=1, reverted_at=NOW(), reverted_by=?
                     WHERE history_id=?",
                    [$current_user['user_id'], $history_id]
                );

                $db->getConnection()->commit();

                $after = $db->getSingle("SELECT status FROM council WHERE council_id=?", [$council_id], 'i');
                $tab = ($after['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';

                header("Location: council.php?tab=$tab&success=undo_edit");
                exit();
            } catch (Throwable $e) {
                $db->getConnection()->rollback();
                $error = 'Undo failed: ' . $e->getMessage();
            }
        }
    }
}

$csrf_token = Security::generateCSRFToken();

function buildPageUrl($page, $tab, $search) {
    $q = ['page' => $page, 'tab' => $tab];
    if ($search !== '') $q['search'] = $search;
    return '?' . http_build_query($q);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Council Members - <?php echo APP_NAME; ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
<style>
/* ============================================================ */
/* BASE                                                          */
/* ============================================================ */
* { box-sizing: border-box; }
body {
    background: #f0f2f5;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    overflow: hidden;
    margin: 0;
    padding: 0;
}
#wrapper {
    display: flex;
    width: 100%;
    height: 100vh;
    overflow: hidden;
}

/* ============================================================ */
/* SIDEBAR — dark theme matching dashboard.php                   */
/* ============================================================ */
#sidebar-wrapper {
    background: #1c1c1d;
    color: #fff;
    width: 250px;
    height: 100vh;
    overflow-y: auto;
    transition: width 0.3s ease;
    box-shadow: 4px 0 10px rgba(0,0,0,0.1);
    white-space: nowrap;
    flex-shrink: 0;
}
#sidebar-wrapper.collapsed { width: 70px; }
#sidebar-wrapper.collapsed .list-group-item span { display: none; }
#sidebar-wrapper.collapsed .list-group-item {
    text-align: center;
    padding: 15px 0;
    justify-content: center;
}
#sidebar-wrapper.collapsed .list-group-item i {
    margin-right: 0;
    width: 100%;
    text-align: center;
    font-size: 1.2rem;
}

#sidebar-wrapper .sidebar-heading {
    padding: 1rem 1rem .85rem;
    font-size: 1.1rem;
    font-weight: 700;
    border-bottom: 1px solid rgba(255,255,255,.1);
    background: #1c1c1d;
    color: white;
    display: flex;
    align-items: center;
    gap: 10px;
    line-height: 1.15;
}
#sidebar-wrapper .sidebar-heading .sidebar-logo {
    height: 34px;
    width: 34px;
    object-fit: contain;
    flex-shrink: 0;
}
#sidebar-wrapper .sidebar-heading .brand-text {
    display: flex;
    flex-direction: column;
    text-align: left;
    flex: 1;
    min-width: 0;
}
#sidebar-wrapper .sidebar-heading .brand-text .brand-acronym {
    font-size: 1.15rem;
    letter-spacing: .5px;
    font-weight: 800;
}
#sidebar-wrapper .sidebar-heading .brand-text .brand-full {
    font-size: .62rem;
    font-weight: 400;
    color: rgba(255,255,255,.7);
    letter-spacing: .2px;
    text-transform: uppercase;
    margin-top: 1px;
    white-space: normal;
    line-height: 1.15;
    max-width: 175px;
}

/* Collapsed: hide sidebar logo AND brand text — logo "moves" to the navbar */
#sidebar-wrapper.collapsed .sidebar-heading .brand-text { display: none; }
#sidebar-wrapper.collapsed .sidebar-heading .sidebar-logo { display: none; }
#sidebar-wrapper.collapsed .sidebar-heading {
    justify-content: center;
    padding: 1rem 0;
    gap: 0;
}

#sidebar-wrapper .list-group-item {
    background: transparent;
    border: none;
    color: rgba(255,255,255,.9);
    padding: .8rem 1.2rem;
    font-weight: 500;
    transition: all .2s;
    font-size: .95rem;
    text-align: left;
    display: flex;
    align-items: center;
    gap: 10px;
}
#sidebar-wrapper .list-group-item i {
    width: 24px;
    text-align: center;
    font-size: 1rem;
    flex-shrink: 0;
}
#sidebar-wrapper .list-group-item:hover,
#sidebar-wrapper .list-group-item.active {
    background: rgba(255,255,255,.15);
    color: #fff;
    border-left: 4px solid #fff;
}
#sidebar-wrapper.collapsed .list-group-item:hover,
#sidebar-wrapper.collapsed .list-group-item.active {
    border-left: none;
    border-bottom: 2px solid #fff;
}
#sidebar-wrapper .list-group-item .badge-count {
    margin-left: auto;
    font-size: .65rem;
    padding: 3px 7px;
    line-height: 1;
    border-radius: 10px;
    flex-shrink: 0;
}
#sidebar-wrapper.collapsed .badge-count { display: none !important; }

/* Menu toggle — larger to match dashboard.php */
.menu-toggle {
    background: transparent;
    border: none;
    color: white;
    font-size: 1.5rem;
    cursor: pointer;
    padding: 0 10px;
    flex-shrink: 0;
    transition: all .2s;
}
.menu-toggle:hover { transform: scale(1.1); }

/* ============================================================ */
/* MAIN CONTENT                                                  */
/* ============================================================ */
#page-content-wrapper {
    flex: 1;
    background: #f0f2f5;
    height: 100vh;
    overflow-y: auto;
    padding: 0;
    min-width: 0;
}

.navbar {
    background: #fff !important;
    box-shadow: 0 2px 4px rgba(0,0,0,0.05);
    padding: 0.7rem 1.2rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
    position: sticky;
    top: 0;
    z-index: 100;
}
.navbar-left { display: flex; align-items: center; gap: 8px; }
.navbar-brand {
    font-size: 1.2rem;
    font-weight: 500;
    color: #1c1c1d !important;
    display: flex;
    align-items: center;
}
.navbar-brand i { color: #1c1c1d; font-size: 1.2rem; }
.navbar-right { display: flex; align-items: center; gap: 20px; }

/* Header logo — appears beside the page title ONLY when sidebar is collapsed */
.header-logo {
    height: 30px;
    width: auto;
    margin-right: 10px;
    vertical-align: middle;
    display: none;
}
#sidebar-wrapper.collapsed ~ #page-content-wrapper .header-logo { display: inline-block; }

/* ============================================================ */
/* ACTION TOOLBAR + SEARCH                                       */
/* ============================================================ */
.action-toolbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    flex-wrap: wrap;
    gap: 15px;
    position: sticky;
    top: 60px;
    background: #f0f2f5;
    z-index: 99;
    padding-top: 10px;
    padding-bottom: 10px;
}
.action-buttons-group { display: flex; gap: 10px; align-items: center; }
.search-group {
    display: flex;
    gap: 10px;
    align-items: center;
    flex: 1;
    max-width: 500px;
}
.search-group form { width: 100%; }
.search-group .search-input {
    flex: 1;
    height: 38px;
    border: 1px solid #dee2e6;
    border-radius: 8px;
    padding: 8px 15px;
    font-size: 14px;
    transition: all 0.3s;
}
.search-group .search-input:focus {
    border-color: #1c1c1d;
    outline: none;
    box-shadow: 0 0 0 2px rgba(28,28,29,0.1);
}
.search-group .btn-search {
    height: 38px;
    padding: 0 18px;
    background: #1c1c1d;
    color: white;
    border: none;
    border-radius: 8px;
    transition: all 0.3s;
}
.search-group .btn-search:hover {
    background: #2c2c2d;
    transform: translateY(-1px);
}

/* Dark primary/success buttons to match theme */
.btn-success {
    background: #1c1c1d;
    border-color: #1c1c1d;
}
.btn-success:hover,
.btn-success:focus,
.btn-success:active {
    background: #2c2c2d !important;
    border-color: #2c2c2d !important;
}

/* Tabs (pills) */
.nav-pills .nav-link {
    color: #495057;
    font-weight: 500;
    transition: all .2s;
}
.nav-pills .nav-link:hover {
    background: #e9ecef;
    color: #1c1c1d;
}
.nav-pills .nav-link.active {
    background: #1c1c1d !important;
    color: #fff !important;
}

/* Cards + tables */
.card {
    border: none;
    border-radius: 10px;
    box-shadow: 0 2px 6px rgba(0,0,0,0.06);
    background: white;
    margin-bottom: 20px;
}
.table { margin-bottom: 0; width: 100%; font-size: 13px; }
.table thead th {
    background: #f8f9fa;
    padding: 12px 12px;
    font-weight: 600;
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #495057;
    border-bottom: 2px solid #dee2e6;
    white-space: nowrap;
}
.table tbody td {
    padding: 10px 12px;
    vertical-align: middle;
    border-bottom: 1px solid #e9ecef;
}
.table tbody tr:hover {
    background-color: rgba(28,28,29,0.04);
}

/* Pagination */
.pagination-wrapper {
    background: white;
    padding: 15px 20px;
    border-top: 1px solid #e9ecef;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 15px;
}
.pagination-info { font-size: 13px; color: #6c757d; }
.pagination { margin: 0; gap: 4px; }
.pagination .page-link {
    color: #1c1c1d;
    padding: 6px 12px;
    font-size: 13px;
    border-radius: 6px;
    border: 1px solid #dee2e6;
    transition: all 0.2s;
}
.pagination .page-link:hover {
    background-color: #1c1c1d;
    color: #fff;
    border-color: #1c1c1d;
    transform: translateY(-1px);
}
.pagination .page-item.active .page-link {
    background-color: #1c1c1d;
    border-color: #1c1c1d;
    color: white;
}
.pagination .page-item.disabled .page-link {
    color: #adb5bd;
    cursor: not-allowed;
    transform: none;
    background: #f8f9fa;
}

/* Custom bits */
.member-thumb,
.member-thumb-placeholder {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    object-fit: cover;
    background: #e9ecef;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    color: #6c757d;
    font-size: .9rem;
}
.position-badge {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 12px;
    font-size: .7rem;
    font-weight: 600;
    background: #e9ecef;
    color: #1c1c1d;
}
.tab-count {
    background: rgba(0,0,0,.08);
    padding: 1px 8px;
    border-radius: 10px;
    font-size: .65rem;
}
.tab-btn.active .tab-count {
    background: rgba(255,255,255,.25);
    color: #fff;
}
.status-badge {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}
.status-active   { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
.status-inactive { background: #fff3cd; color: #856404; border: 1px solid #ffeeba; }

.edit-change-old { color: #c0392b; text-decoration: line-through; }
.edit-change-new { color: #28a745; font-weight: 600; }
.edit-diff-line {
    font-size: .75rem;
    line-height: 1.4;
    margin-bottom: 2px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 420px;
}
.edit-diff-field { font-weight: 600; color: #495057; margin-right: 4px; }
.photo-upload-area { text-align: center; margin-bottom: 16px; }
.photo-upload-area img {
    width: 96px;
    height: 96px;
    border-radius: 50%;
    object-fit: cover;
    border: 3px solid #dee2e6;
}

/* Modal header dark accent */
.modal-header.bg-dark,
.modal-header.bg-primary {
    background: #1c1c1d !important;
    color: #fff !important;
}

/* Responsive */
@media (max-width: 768px) {
    #sidebar-wrapper {
        position: fixed;
        left: 0; top: 0;
        transform: translateX(-100%);
        z-index: 1050;
        transition: transform .3s ease;
    }
    #sidebar-wrapper.mobile-open { transform: translateX(0); }
    #sidebar-wrapper .sidebar-heading .brand-text .brand-full { display: block; }
    .header-logo { display: inline-block !important; }
}
</style>
</head>
<body>
<div id="wrapper">
    <div id="sidebar-wrapper">
        <div class="sidebar-heading">
            <img src="../assets/images/harana-logo.png" alt="NHGL" class="sidebar-logo" onerror="this.style.display='none';">
            <span class="brand-text">
                <span class="brand-acronym">NHGL</span>
                <span class="brand-full">Nagkaisang Haranista sa Gintong Luzon</span>
            </span>
            <button class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></button>
        </div>
        <div class="list-group list-group-flush mt-2">
            <a href="dashboard.php" class="list-group-item"><i class="fas fa-tachometer-alt"></i><span>Dashboard</span></a>
            <a href="members.php" class="list-group-item"><i class="fas fa-users"></i><span>Members</span></a>
            <a href="manage_chapters.php" class="list-group-item"><i class="fas fa-sitemap"></i><span>Chapters &amp; Groups</span></a>
           <a href="leader_assignments.php" class="list-group-item <?php echo $current_page == 'leader_assignments.php' ? 'active' : ''; ?>"><i class="fas fa-user-shield"></i><span>Leader Assignment</span></a>
            <a href="council.php" class="list-group-item active"><i class="fas fa-user-tie"></i><span>Council</span></a>
            <a href="payments.php" class="list-group-item"><i class="fas fa-credit-card"></i><span>Payments</span></a>
            <a href="remittances.php" class="list-group-item"><i class="fas fa-hand-holding-usd"></i><span>Remittances</span></a>
            <a href="reports.php" class="list-group-item"><i class="fas fa-chart-bar"></i><span>Reports</span></a>
            <a href="announcements.php" class="list-group-item"><i class="fas fa-bullhorn"></i><span>Send Announcement</span></a>
            <?php if ($current_user['role'] === 'admin'): ?>
            <a href="pending_users.php" class="list-group-item">
                <i class="fas fa-user-clock"></i><span>Pending</span>
                <?php if ($pending_count > 0): ?>
                    <span class="badge bg-danger badge-count"><?php echo $pending_count; ?></span>
                <?php endif; ?>
            </a>
         
            <?php endif; ?>
            <a href="settings.php" class="list-group-item"><i class="fas fa-cog"></i><span>Settings</span></a>
            <a href="../logout.php" class="list-group-item" onclick="return confirm('Are you sure?');"><i class="fas fa-sign-out-alt"></i><span>Logout</span></a>
        </div>
    </div>

    <div id="page-content-wrapper">
        <nav class="navbar navbar-light bg-light">
            <div class="navbar-left">
                <img src="../assets/images/harana-logo.png" alt="Harana" class="header-logo" id="headerLogo" onerror="this.style.display='none';">
                <span class="navbar-brand"><i class="fas fa-user-tie me-2"></i>Council Management</span>
            </div>
            <div class="navbar-right">
                <span class="text-muted small d-none d-sm-inline"><i class="fas fa-calendar-alt me-1"></i><?php echo date('F j, Y'); ?></span>
                <span class="small"><i class="fas fa-user-circle me-2"></i><?php echo htmlspecialchars($current_user['full_name'] ?? $current_user['username']); ?></span>
            </div>
        </nav>

        <div class="container-fluid p-3">
            <div class="toast-container position-fixed top-0 end-0 p-3" style="z-index:999999;top:70px!important;">
                <div id="successToast" class="toast align-items-center text-white bg-success border-0">
                    <div class="d-flex"><div class="toast-body"><i class="fas fa-check-circle me-2"></i><span id="successMessage"></span></div>
                    <button class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>
                </div>
                <div id="errorToast" class="toast align-items-center text-white bg-danger border-0">
                    <div class="d-flex"><div class="toast-body"><i class="fas fa-exclamation-circle me-2"></i><span id="errorMessage"></span></div>
                    <button class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>
                </div>
            </div>

            <div class="action-toolbar">
                <div class="search-group">
                    <form method="GET" class="d-flex w-100 gap-2">
                        <input type="hidden" name="tab" value="<?php echo $active_tab; ?>">
                        <input type="text" name="search" class="search-input" placeholder="Search..." value="<?php echo htmlspecialchars($search); ?>">
                        <button type="submit" class="btn-search"><i class="fas fa-search"></i> Search</button>
                    </form>
                </div>
                <div class="action-buttons-group">
                    <?php if ($active_tab !== 'deleted' && $active_tab !== 'edited'): ?>
                    <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addCouncilModal">
                        <i class="fas fa-plus me-1"></i>Add Council
                    </button>
                    <?php endif; ?>
                </div>
            </div>

            <ul class="nav nav-pills mb-3" style="gap:6px;">
                <?php foreach ([
                    'active'   => ['user-check', 'Active',   $active_count],
                    'inactive' => ['user-slash', 'Inactive', $inactive_count],
                    'edited'   => ['history',    'Edited',   $edited_count],
                    'deleted'  => ['trash',      'Deleted',  $deleted_count],
                ] as $k => $v): ?>
                <li class="nav-item">
                    <a class="nav-link tab-btn <?php echo $active_tab === $k ? 'active' : ''; ?>"
                       style="border-radius:8px;font-size:.85rem;padding:6px 14px;"
                       href="?tab=<?php echo $k; ?><?php echo $search ? '&search='.urlencode($search) : ''; ?>">
                        <i class="fas fa-<?php echo $v[0]; ?> me-1"></i> <?php echo $v[1]; ?>
                        <span class="tab-count ms-1"><?php echo $v[2]; ?></span>
                    </a>
                </li>
                <?php endforeach; ?>
            </ul>

            <div class="card shadow-sm">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <?php if ($active_tab === 'edited'): ?>
                        <!-- ============ EDITED TABLE ============ -->
                        <table class="table table-hover mb-0 align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th style="width:24%;">Member</th>
                                    <th>Changes (Old → New)</th>
                                    <th style="width:11%;">Edited By</th>
                                    <th style="width:13%;">When</th>
                                    <th style="width:150px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (empty($rows)): ?>
                                <tr><td colspan="5" class="text-center py-5">
                                    <i class="fas fa-history fa-3x text-muted mb-3"></i>
                                    <p class="text-muted mb-0">
                                        <?php echo $search ? 'No edits match "'.htmlspecialchars($search).'".' : 'No edit history yet.'; ?>
                                    </p>
                                </td></tr>
                            <?php else: foreach ($rows as $r):
                                $changes = json_decode($r['changes'] ?? '[]', true) ?: [];
                            ?>
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <?php if (!empty($r['photo'])): ?>
                                                <img src="../<?php echo htmlspecialchars($r['photo']); ?>" class="member-thumb" onerror="this.src='../assets/images/default-avatar.png'">
                                            <?php else: ?>
                                                <div class="member-thumb-placeholder"><i class="fas fa-user"></i></div>
                                            <?php endif; ?>
                                            <div>
                                                <strong><?php echo htmlspecialchars($r['full_name'] ?? '(deleted member)'); ?></strong>
                                                <?php if (!empty($r['position'])): ?>
                                                    <br><span class="position-badge"><?php echo htmlspecialchars($r['position']); ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if (empty($changes)): ?>
                                            <span class="text-muted small">No field changes recorded</span>
                                        <?php else: foreach ($changes as $field => $pair):
                                            $label = ucwords(str_replace('_', ' ', $field));
                                            $old   = $pair['old'] ?? '';
                                            $new   = $pair['new'] ?? '';
                                        ?>
                                            <div class="edit-diff-line">
                                                <span class="edit-diff-field"><?php echo htmlspecialchars($label); ?>:</span>
                                                <span class="edit-change-old"><?php echo htmlspecialchars($old !== '' ? $old : '(empty)'); ?></span>
                                                <i class="fas fa-arrow-right mx-1 text-muted small"></i>
                                                <span class="edit-change-new"><?php echo htmlspecialchars($new !== '' ? $new : '(empty)'); ?></span>
                                            </div>
                                        <?php endforeach; endif; ?>
                                    </td>
                                    <td><small><?php echo htmlspecialchars($r['edited_by_name'] ?? 'System'); ?></small></td>
                                    <td><small><?php echo !empty($r['edited_at']) ? date('M d, Y H:i', strtotime($r['edited_at'])) : '—'; ?></small></td>
                                    <td>
                                        <button class="btn btn-sm btn-warning me-1"
                                            onclick='showUndoEditModal(<?php echo (int)$r["council_id"]; ?>, <?php echo (int)$r["history_id"]; ?>, <?php echo json_encode($r["full_name"] ?? "Unknown"); ?>)'
                                            title="Undo this edit">
                                            <i class="fas fa-undo me-1"></i>Undo
                                        </button>
                                        <button class="btn btn-sm btn-outline-danger"
                                            onclick='showDeleteModal(<?php echo (int)$r["council_id"]; ?>, <?php echo json_encode($r["full_name"] ?? "Unknown"); ?>)'
                                            title="Delete member">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                            </tbody>
                        </table>

                        <?php else: ?>
                        <!-- ============ NORMAL / DELETED TABLE ============ -->
                        <table class="table table-hover mb-0 align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th style="width:60px;">Photo</th>
                                    <th>Full Name</th><th>Position</th><th>Contact</th><th>Email</th><th>Term</th>
                                    <?php if ($active_tab === 'deleted'): ?><th>Deleted</th><?php endif; ?>
                                    <th>Status</th><th style="width:80px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (empty($rows)): ?>
                                <tr><td colspan="8" class="text-center py-5">
                                    <i class="fas fa-user-tie fa-3x text-muted mb-3"></i>
                                    <p class="text-muted mb-0">
                                        <?php if ($search) echo 'No results for "'.htmlspecialchars($search).'".';
                                        elseif ($active_tab === 'deleted') echo 'No deleted council members.';
                                        else echo 'No council members. Click "Add Council".'; ?>
                                    </p>
                                </td></tr>
                            <?php else: foreach ($rows as $r): ?>
                                <?php if ($active_tab === 'deleted'):
                                    $data = json_decode($r['original_data'] ?? '{}', true) ?: [];
                                ?>
                                    <tr>
                                        <td>
                                            <?php if (!empty($data['photo'])): ?>
                                                <img src="../<?php echo htmlspecialchars($data['photo']); ?>" class="member-thumb" onerror="this.src='../assets/images/default-avatar.png'">
                                            <?php else: ?><div class="member-thumb-placeholder"><i class="fas fa-user"></i></div><?php endif; ?>
                                        </td>
                                        <td><strong><?php echo htmlspecialchars($data['full_name'] ?? '—'); ?></strong></td>
                                        <td><span class="position-badge"><?php echo htmlspecialchars($data['position'] ?? '—'); ?></span></td>
                                        <td><?php echo htmlspecialchars($data['contact_number'] ?? '—'); ?></td>
                                        <td><?php echo htmlspecialchars($data['email'] ?? '—'); ?></td>
                                        <td><small>
                                            <?php echo !empty($data['term_start']) ? date('M Y', strtotime($data['term_start'])) : '—'; ?>
                                            –
                                            <?php echo !empty($data['term_end'])   ? date('M Y', strtotime($data['term_end']))   : '—'; ?>
                                        </small></td>
                                        <td><small><?php echo !empty($r['deleted_at']) ? date('M d, Y H:i', strtotime($r['deleted_at'])) : '—'; ?></small></td>
                                        <td><span class="status-badge status-inactive">Deleted</span></td>
                                        <td>
                                            <div class="dropdown">
                                                <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="dropdown"><i class="fas fa-ellipsis-v"></i></button>
                                                <ul class="dropdown-menu dropdown-menu-end">
                                                    <li><a class="dropdown-item" href="#"
                                                        onclick='event.preventDefault(); showRecoverModal(<?php echo (int)$r["delete_id"]; ?>, <?php echo json_encode($data["full_name"] ?? "Unknown"); ?>);'>
                                                        <i class="fas fa-trash-restore text-success me-2"></i>Recover</a></li>
                                                    <li><hr class="dropdown-divider"></li>
                                                    <li><a class="dropdown-item text-danger" href="#"
                                                        onclick='event.preventDefault(); showPermanentDeleteModal(<?php echo (int)$r["delete_id"]; ?>, <?php echo json_encode($data["full_name"] ?? "Unknown"); ?>);'>
                                                        <i class="fas fa-skull-crossbones me-2"></i>Permanently Delete</a></li>
                                                </ul>
                                            </div>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <tr>
                                        <td>
                                            <?php if (!empty($r['photo'])): ?>
                                                <img src="../<?php echo htmlspecialchars($r['photo']); ?>" class="member-thumb" onerror="this.src='../assets/images/default-avatar.png'">
                                            <?php else: ?><div class="member-thumb-placeholder"><i class="fas fa-user"></i></div><?php endif; ?>
                                        </td>
                                        <td><strong><?php echo htmlspecialchars($r['full_name']); ?></strong></td>
                                        <td><span class="position-badge"><?php echo htmlspecialchars($r['position']); ?></span></td>
                                        <td><?php echo htmlspecialchars($r['contact_number'] ?: '—'); ?></td>
                                        <td><?php echo htmlspecialchars($r['email'] ?: '—'); ?></td>
                                        <td><small>
                                            <?php echo $r['term_start'] ? date('M Y', strtotime($r['term_start'])) : '—'; ?>
                                            –
                                            <?php echo $r['term_end'] ? date('M Y', strtotime($r['term_end'])) : '—'; ?>
                                        </small></td>
                                        <td>
                                            <span class="status-badge <?php echo $r['status'] === 'active' ? 'status-active' : 'status-inactive'; ?>">
                                                <?php echo ucfirst($r['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="dropdown">
                                                <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="dropdown"><i class="fas fa-ellipsis-v"></i></button>
                                                <ul class="dropdown-menu dropdown-menu-end">
                                                    <li><a class="dropdown-item" href="#"
                                                        onclick='event.preventDefault(); viewMemberDetails(<?php echo (int)$r["council_id"]; ?>);'>
                                                        <i class="fas fa-eye text-info me-2"></i>View Details</a></li>
                                                    <li><a class="dropdown-item" href="#"
                                                        onclick='event.preventDefault(); editCouncil(<?php echo json_encode([
                                                            "id"          => (int)$r["council_id"],
                                                            "last_name"   => $r["last_name"]   ?? "",
                                                            "first_name"  => $r["first_name"]  ?? "",
                                                            "middle_name" => $r["middle_name"] ?? "",
                                                            "full_name"   => $r["full_name"]   ?? "",
                                                            "position"    => $r["position"]    ?? "",
                                                            "contact"     => $r["contact_number"] ?? "",
                                                            "email"       => $r["email"]       ?? "",
                                                            "term_start"  => $r["term_start"]  ?? "",
                                                            "term_end"    => $r["term_end"]    ?? "",
                                                            "status"      => $r["status"]      ?? "active",
                                                            "photo"       => $r["photo"]       ?? "",
                                                        ], JSON_HEX_APOS | JSON_HEX_QUOT); ?>);'>
                                                        <i class="fas fa-edit text-primary me-2"></i>Edit</a></li>
                                                    <?php if ($r['status'] === 'active'): ?>
                                                    <li><a class="dropdown-item" href="#"
                                                        onclick='event.preventDefault(); showStatusModal(<?php echo (int)$r["council_id"]; ?>, "inactive", <?php echo json_encode($r["full_name"]); ?>);'>
                                                        <i class="fas fa-user-slash text-warning me-2"></i>Set Inactive</a></li>
                                                    <?php else: ?>
                                                    <li><a class="dropdown-item" href="#"
                                                        onclick='event.preventDefault(); showStatusModal(<?php echo (int)$r["council_id"]; ?>, "active", <?php echo json_encode($r["full_name"]); ?>);'>
                                                        <i class="fas fa-user-check text-success me-2"></i>Set Active</a></li>
                                                    <?php endif; ?>
                                                    <li><hr class="dropdown-divider"></li>
                                                    <li><a class="dropdown-item text-danger" href="#"
                                                        onclick='event.preventDefault(); showDeleteModal(<?php echo (int)$r["council_id"]; ?>, <?php echo json_encode($r["full_name"]); ?>);'>
                                                        <i class="fas fa-trash me-2"></i>Delete</a></li>
                                                </ul>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                        <?php endif; ?>
                    </div>

                    <?php if ($total_pages > 1): ?>
                    <div class="pagination-wrapper">
                        <div class="pagination-info">
                            Showing <?php echo $offset + 1; ?> – <?php echo min($offset + $per_page, $total_records); ?>
                            of <?php echo $total_records; ?> records
                        </div>
                        <nav><ul class="pagination">
                            <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                <a class="page-link" href="<?php echo buildPageUrl(1, $active_tab, $search); ?>"><i class="fas fa-angle-double-left"></i></a></li>
                            <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                <a class="page-link" href="<?php echo buildPageUrl($page - 1, $active_tab, $search); ?>"><i class="fas fa-chevron-left"></i></a></li>
                            <?php
                            $start = max(1, $page - 2);
                            $end   = min($total_pages, $page + 2);
                            for ($i = $start; $i <= $end; $i++):
                            ?>
                            <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                <a class="page-link" href="<?php echo buildPageUrl($i, $active_tab, $search); ?>"><?php echo $i; ?></a></li>
                            <?php endfor; ?>
                            <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                <a class="page-link" href="<?php echo buildPageUrl($page + 1, $active_tab, $search); ?>"><i class="fas fa-chevron-right"></i></a></li>
                            <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                <a class="page-link" href="<?php echo buildPageUrl($total_pages, $active_tab, $search); ?>"><i class="fas fa-angle-double-right"></i></a></li>
                        </ul></nav>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ADD MODAL -->
<div class="modal fade" id="addCouncilModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog"><div class="modal-content">
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="action" value="add">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title"><i class="fas fa-user-plus me-2"></i>Add Council Member</h5>
                <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-md-4 mb-3"><label class="form-label">Last Name *</label><input class="form-control" name="last_name" id="add_last_name" required></div>
                    <div class="col-md-4 mb-3"><label class="form-label">First Name *</label><input class="form-control" name="first_name" id="add_first_name" required></div>
                    <div class="col-md-4 mb-3"><label class="form-label">Middle Name</label><input class="form-control" name="middle_name" id="add_middle_name"></div>
                </div>
                <div class="mb-3"><label class="form-label">Full Name (auto)</label><input class="form-control" id="add_full_name_display" readonly></div>
                <div class="mb-3"><label class="form-label">Position *</label>
                    <select class="form-select" name="position" required>
                        <option value="">Select Position</option>
                        <?php foreach ($positions as $p): ?><option value="<?php echo htmlspecialchars($p); ?>"><?php echo htmlspecialchars($p); ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3"><label class="form-label">Contact</label><input class="form-control" name="contact_number"></div>
                <div class="mb-3"><label class="form-label">Email</label><input type="email" class="form-control" name="email"></div>
                <div class="row">
                    <div class="col-md-6 mb-3"><label class="form-label">Term Start</label><input type="date" class="form-control" name="term_start"></div>
                    <div class="col-md-6 mb-3"><label class="form-label">Term End</label><input type="date" class="form-control" name="term_end"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" data-bs-dismiss="modal" type="button">Cancel</button>
                <button class="btn btn-success" type="submit">Add Member</button>
            </div>
        </form>
    </div></div>
</div>

<!-- EDIT MODAL -->
<div class="modal fade" id="editCouncilModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog"><div class="modal-content">
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="council_id" id="edit_council_id">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title"><i class="fas fa-user-edit me-2"></i>Edit Council Member</h5>
                <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="photo-upload-area"><img id="edit_photo_preview" src="../assets/images/default-avatar.png"></div>
                <div class="row">
                    <div class="col-md-4 mb-3"><label class="form-label">Last Name *</label><input class="form-control" name="last_name" id="edit_last_name" required></div>
                    <div class="col-md-4 mb-3"><label class="form-label">First Name *</label><input class="form-control" name="first_name" id="edit_first_name" required></div>
                    <div class="col-md-4 mb-3"><label class="form-label">Middle Name</label><input class="form-control" name="middle_name" id="edit_middle_name"></div>
                </div>
                <div class="mb-3"><label class="form-label">Full Name (auto)</label><input class="form-control" id="edit_full_name_display" readonly></div>
                <div class="mb-3"><label class="form-label">Position *</label>
                    <select class="form-select" name="position" id="edit_position" required>
                        <option value="">Select Position</option>
                        <?php foreach ($positions as $p): ?><option value="<?php echo htmlspecialchars($p); ?>"><?php echo htmlspecialchars($p); ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3"><label class="form-label">Contact</label><input class="form-control" name="contact_number" id="edit_contact"></div>
                <div class="mb-3"><label class="form-label">Email</label><input type="email" class="form-control" name="email" id="edit_email"></div>
                <div class="row">
                    <div class="col-md-6 mb-3"><label class="form-label">Term Start</label><input type="date" class="form-control" name="term_start" id="edit_term_start"></div>
                    <div class="col-md-6 mb-3"><label class="form-label">Term End</label><input type="date" class="form-control" name="term_end" id="edit_term_end"></div>
                </div>
                <div class="mb-3"><label class="form-label">Status</label>
                    <select class="form-select" name="status" id="edit_status">
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" data-bs-dismiss="modal" type="button">Cancel</button>
                <button class="btn btn-primary" type="submit">Update Member</button>
            </div>
        </form>
    </div></div>
</div>

<!-- VIEW MODAL -->
<div class="modal fade" id="viewCouncilModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg"><div class="modal-content">
        <div class="modal-header bg-dark text-white">
            <h5 class="modal-title"><i class="fas fa-file-alt me-2"></i>Council Member Details</h5>
            <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body" id="viewModalBody"><div class="text-center p-4"><div class="spinner-border text-dark"></div></div></div>
        <div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">Close</button></div>
    </div></div>
</div>

<!-- DELETE MODAL -->
<div class="modal fade" id="deleteCouncilModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog"><div class="modal-content">
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="council_id" id="delete_council_id">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="fas fa-trash-alt me-2"></i>Confirm Delete</h5>
                <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Delete <strong id="delete_council_name"></strong>?</p>
                <div class="alert alert-warning mb-0"><i class="fas fa-info-circle me-2"></i>Moved to <strong>Deleted</strong> tab — recoverable.</div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" data-bs-dismiss="modal" type="button">Cancel</button>
                <button class="btn btn-danger" type="submit">Yes, Delete</button>
            </div>
        </form>
    </div></div>
</div>

<!-- RECOVER MODAL -->
<div class="modal fade" id="recoverCouncilModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog"><div class="modal-content">
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="action" value="recover">
            <input type="hidden" name="delete_id" id="recover_delete_id">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="fas fa-trash-restore me-2"></i>Recover Council Member</h5>
                <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body"><p>Restore <strong id="recover_council_name"></strong>?</p></div>
            <div class="modal-footer">
                <button class="btn btn-secondary" data-bs-dismiss="modal" type="button">Cancel</button>
                <button class="btn btn-success" type="submit">Recover</button>
            </div>
        </form>
    </div></div>
</div>

<!-- PERMANENT DELETE MODAL -->
<div class="modal fade" id="permanentDeleteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog"><div class="modal-content">
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="action" value="permanent_delete">
            <input type="hidden" name="delete_id" id="permanent_delete_id">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title"><i class="fas fa-skull-crossbones me-2"></i>Permanent Delete</h5>
                <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Permanently delete <strong id="permanent_delete_name"></strong>?</p>
                <p class="text-danger mb-0"><small><i class="fas fa-exclamation-triangle me-1"></i>Cannot be undone.</small></p>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" data-bs-dismiss="modal" type="button">Cancel</button>
                <button class="btn btn-danger" type="submit">Permanently Delete</button>
            </div>
        </form>
    </div></div>
</div>

<!-- STATUS CHANGE MODAL -->
<div class="modal fade" id="statusModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog"><div class="modal-content">
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="action" value="set_status">
            <input type="hidden" name="council_id" id="status_council_id">
            <input type="hidden" name="new_status" id="status_new_status">
            <div class="modal-header" id="statusModalHeader">
                <h5 class="modal-title" id="statusModalTitle">Change Status</h5>
                <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body"><p id="statusModalBody">Change status?</p></div>
            <div class="modal-footer">
                <button class="btn btn-secondary" data-bs-dismiss="modal" type="button">Cancel</button>
                <button class="btn" id="statusModalConfirm" type="submit">Confirm</button>
            </div>
        </form>
    </div></div>
</div>

<!-- UNDO EDIT MODAL -->
<div class="modal fade" id="undoEditModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg"><div class="modal-content">
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="action" value="undo_edit">
            <input type="hidden" name="council_id" id="undo_council_id">
            <input type="hidden" name="history_id" id="undo_history_id">
            <div class="modal-header bg-warning">
                <h5 class="modal-title"><i class="fas fa-undo me-2"></i>Undo Last Edit</h5>
                <button class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Revert this edit for <strong id="undo_council_name"></strong>?</p>
                <div id="undo_changes_preview" class="mt-3"></div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" data-bs-dismiss="modal" type="button">Cancel</button>
                <button class="btn btn-warning" type="submit">Undo Edit</button>
            </div>
        </form>
    </div></div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
const sidebar = document.getElementById('sidebar-wrapper');
document.getElementById('menuToggle')?.addEventListener('click', () => {
    sidebar.classList.toggle('collapsed');
    localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
});
if (localStorage.getItem('sidebarCollapsed') === 'true') sidebar.classList.add('collapsed');

const successToast = new bootstrap.Toast(document.getElementById('successToast'));
const errorToast   = new bootstrap.Toast(document.getElementById('errorToast'));
<?php if ($success_toast): ?>
document.getElementById('successMessage').textContent = <?php echo json_encode($success_toast); ?>;
successToast.show();
<?php endif; ?>
<?php if ($error): ?>
document.getElementById('errorMessage').textContent = <?php echo json_encode($error); ?>;
errorToast.show();
<?php endif; ?>

function updateFullName(prefix) {
    const l = $(`#${prefix}_last_name`).val().trim();
    const f = $(`#${prefix}_first_name`).val().trim();
    const m = $(`#${prefix}_middle_name`).val().trim();
    let full = '';
    if (l) full += l;
    if (f) full += (full ? ', ' : '') + f;
    if (m) full += ' ' + m;
    $(`#${prefix}_full_name_display`).val(full);
}
$('#add_last_name,#add_first_name,#add_middle_name').on('input', () => updateFullName('add'));
$('#edit_last_name,#edit_first_name,#edit_middle_name').on('input', () => updateFullName('edit'));

function editCouncil(d) {
    $('#edit_council_id').val(d.id);
    $('#edit_last_name').val(d.last_name || '');
    $('#edit_first_name').val(d.first_name || '');
    $('#edit_middle_name').val(d.middle_name || '');
    $('#edit_full_name_display').val(d.full_name || '');
    $('#edit_position').val(d.position || '');
    $('#edit_contact').val(d.contact || '');
    $('#edit_email').val(d.email || '');
    $('#edit_term_start').val(d.term_start || '');
    $('#edit_term_end').val(d.term_end || '');
    $('#edit_status').val(d.status || 'active');
    $('#edit_photo_preview').attr('src', d.photo ? '../' + d.photo : '../assets/images/default-avatar.png');
    new bootstrap.Modal(document.getElementById('editCouncilModal')).show();
}

function viewMemberDetails(id) {
    const modal = new bootstrap.Modal(document.getElementById('viewCouncilModal'));
    $('#viewModalBody').html('<div class="text-center p-4"><div class="spinner-border text-dark"></div></div>');
    modal.show();
    $.getJSON('get_council_member.php', { id: id, t: Date.now() })
        .done(res => {
            if (!res || !res.success || !res.data) {
                $('#viewModalBody').html('<div class="alert alert-danger">Failed to load.</div>');
                return;
            }
            const m = res.data;
            const photo = m.photo ? '../' + m.photo : '../assets/images/default-avatar.png';
            $('#viewModalBody').html(`
                <div class="text-center mb-4">
                    <img src="${photo}" class="rounded-circle" style="width:100px;height:100px;object-fit:cover;border:3px solid #dee2e6;" onerror="this.src='../assets/images/default-avatar.png'">
                    <h4 class="mt-3 mb-1">${m.full_name || ''}</h4>
                    <span class="position-badge">${m.position || ''}</span>
                    <span class="status-badge ${m.status === 'active' ? 'status-active' : 'status-inactive'} ms-2">${(m.status || '').toUpperCase()}</span>
                </div>
                <table class="table table-sm">
                    <tr><th style="width:30%">Contact</th><td>${m.contact_number || '—'}</td></tr>
                    <tr><th>Email</th><td>${m.email || '—'}</td></tr>
                    <tr><th>Term Start</th><td>${m.term_start || '—'}</td></tr>
                    <tr><th>Term End</th><td>${m.term_end || '—'}</td></tr>
                </table>`);
        })
        .fail(() => $('#viewModalBody').html('<div class="alert alert-danger">Error loading.</div>'));
}

function showDeleteModal(id, name) {
    $('#delete_council_id').val(id);
    $('#delete_council_name').text(name);
    new bootstrap.Modal(document.getElementById('deleteCouncilModal')).show();
}
function showRecoverModal(id, name) {
    $('#recover_delete_id').val(id);
    $('#recover_council_name').text(name);
    new bootstrap.Modal(document.getElementById('recoverCouncilModal')).show();
}
function showPermanentDeleteModal(id, name) {
    $('#permanent_delete_id').val(id);
    $('#permanent_delete_name').text(name);
    new bootstrap.Modal(document.getElementById('permanentDeleteModal')).show();
}

function showStatusModal(id, newStatus, name) {
    $('#status_council_id').val(id);
    $('#status_new_status').val(newStatus);
    const header  = document.getElementById('statusModalHeader');
    const confirm = document.getElementById('statusModalConfirm');
    if (newStatus === 'inactive') {
        header.className = 'modal-header bg-warning';
        $('#statusModalTitle').html('<i class="fas fa-user-slash me-2"></i>Set Inactive');
        $('#statusModalBody').html(`Set <strong>${name}</strong> as <span class="badge bg-warning text-dark">INACTIVE</span>?`);
        confirm.className = 'btn btn-warning';
        confirm.textContent = 'Set Inactive';
    } else {
        header.className = 'modal-header bg-success text-white';
        $('#statusModalTitle').html('<i class="fas fa-user-check me-2"></i>Set Active');
        $('#statusModalBody').html(`Reactivate <strong>${name}</strong>?`);
        confirm.className = 'btn btn-success';
        confirm.textContent = 'Set Active';
    }
    new bootstrap.Modal(document.getElementById('statusModal')).show();
}

function showUndoEditModal(councilId, historyId, name) {
    $('#undo_council_id').val(councilId);
    $('#undo_history_id').val(historyId);
    $('#undo_council_name').text(name);
    $('#undo_changes_preview').html('<div class="text-muted small">Loading changes…</div>');
    $.getJSON('council.php', { get_edit_details: 1, history_id: historyId })
        .done(res => {
            if (!res || !res.success || !res.changes || Object.keys(res.changes).length === 0) {
                $('#undo_changes_preview').html('<div class="text-muted small">No details available.</div>');
                return;
            }
            let h = '<table class="table table-sm"><thead class="table-light"><tr><th>Field</th><th>Old</th><th>New</th></tr></thead><tbody>';
            Object.keys(res.changes).forEach(f => {
                const o = res.changes[f].old ?? '';
                const n = res.changes[f].new ?? '';
                const label = f.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
                h += `<tr><td><strong>${label}</strong></td><td class="edit-change-old">${o || '(empty)'}</td><td class="edit-change-new">${n || '(empty)'}</td></tr>`;
            });
            $('#undo_changes_preview').html(h + '</tbody></table>');
        })
        .fail(() => $('#undo_changes_preview').html('<div class="text-muted small">Could not load change details.</div>'));
    new bootstrap.Modal(document.getElementById('undoEditModal')).show();
}
</script>
</body>
</html>