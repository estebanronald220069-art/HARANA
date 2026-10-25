<?php
// admin/leader_assignments.php
require_once '../includes/config.php';
require_once '../includes/db_connection.php';
require_once '../includes/security.php';
require_once '../includes/auth.php';

$auth->requireLogin();
$current_user = $auth->getCurrentUser();
if ($current_user['role'] !== 'admin') { header('Location: dashboard.php'); exit; }

$db = Database::getInstance();
$pending_count = (int)($db->getSingle("SELECT COUNT(*) cnt FROM pending_users WHERE status='pending'")['cnt'] ?? 0);

$msg = ''; $err = '';

// ---------------- Handle POST ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';

    if ($action === 'assign') {
        $leader_id = (int)($_POST['leader_user_id'] ?? 0);
        $group_id  = (int)($_POST['group_id'] ?? 0);

        if ($leader_id <= 0 || $group_id <= 0) {
            $err = 'Select both a leader and a group.';
        } else {
            $u = $db->getSingle("SELECT user_id, role, username FROM users WHERE user_id = ?", [$leader_id], 'i');
            if (!$u) {
                $err = 'That user no longer exists.';
            } elseif ($u['role'] !== 'leader') {
                $err = "User '{$u['username']}' does not have the leader role (current role: {$u['role']}).";
            } else {
                $exists = $db->getSingle(
                    "SELECT assignment_id FROM leader_group_assignments
                     WHERE leader_user_id = ? AND group_id = ?",
                    [$leader_id, $group_id], 'ii'
                );
                if ($exists) {
                    $err = 'That leader is already assigned to that group.';
                } else {
                    try {
                        $db->execute(
                            "INSERT INTO leader_group_assignments (leader_user_id, group_id, assigned_by)
                             VALUES (?, ?, ?)",
                            [$leader_id, $group_id, $current_user['user_id']], 'iii'
                        );
                        $msg = 'Leader assigned.';
                        Security::logEvent('LEADER_ASSIGNED', "Leader user_id=$leader_id → group_id=$group_id");
                    } catch (Throwable $e) {
                        $err = 'Failed: ' . $e->getMessage();
                    }
                }
            }
        }
    }

    elseif ($action === 'unassign') {
        $id = (int)($_POST['assignment_id'] ?? 0);
        if ($id > 0) {
            $db->execute("DELETE FROM leader_group_assignments WHERE assignment_id = ?", [$id], 'i');
            $msg = 'Assignment removed.';
            Security::logEvent('LEADER_UNASSIGNED', "Assignment $id removed");
        }
    }

    elseif ($action === 'promote_user') {
        $user_id = (int)($_POST['user_id'] ?? 0);
        if ($user_id <= 0) {
            $err = 'Pick a user to promote.';
        } else {
            $u = $db->getSingle("SELECT user_id, username, role FROM users WHERE user_id = ?", [$user_id], 'i');
            if (!$u) {
                $err = 'User not found.';
            } elseif ($u['role'] === 'leader') {
                $err = "User '{$u['username']}' is already a leader.";
            } else {
                $db->execute("UPDATE users SET role = 'leader' WHERE user_id = ?", [$user_id], 'i');
                $msg = "User '{$u['username']}' promoted to leader. Now select them in the top form and assign to a group.";
                Security::logEvent('LEADER_PROMOTED', "user_id=$user_id ({$u['username']}) promoted to leader");
            }
        }
    }

    elseif ($action === 'demote_leader') {
        $user_id = (int)($_POST['user_id'] ?? 0);
        if ($user_id === (int)$current_user['user_id']) {
            $err = 'You cannot demote yourself.';
        } elseif ($user_id > 0) {
            $db->execute("DELETE FROM leader_group_assignments WHERE leader_user_id = ?", [$user_id], 'i');
            $db->execute("UPDATE users SET role = 'viewer' WHERE user_id = ?", [$user_id], 'i');
            $msg = 'Leader demoted to viewer. All their assignments removed.';
            Security::logEvent('LEADER_DEMOTED', "user_id=$user_id demoted");
        }
    }
}

// ---------------- Fetch data ----------------
$leaders = $db->getAll(
    "SELECT user_id, username, full_name, email, contact_number
     FROM users
     WHERE role = 'leader' AND is_active = 1
     ORDER BY COALESCE(NULLIF(full_name,''), username) ASC"
);

$all_groups = $db->getAll(
    "SELECT g.group_id, g.group_name, c.chapter_name
     FROM groups g
     JOIN chapters c ON c.chapter_id = g.chapter_id
     WHERE g.is_active = 1
     ORDER BY c.chapter_name, g.group_name"
);

$assignments = $db->getAll(
    "SELECT a.assignment_id, a.assigned_at,
            u.user_id, u.full_name AS leader_name, u.username,
            g.group_name, c.chapter_name
     FROM leader_group_assignments a
     JOIN users u    ON u.user_id   = a.leader_user_id
     JOIN groups g   ON g.group_id  = a.group_id
     JOIN chapters c ON c.chapter_id = g.chapter_id
     ORDER BY c.chapter_name, g.group_name"
);

$candidates = $db->getAll(
    "SELECT user_id, username, full_name, email, role
     FROM users
     WHERE role IN ('viewer','treasurer') AND is_active = 1
     ORDER BY COALESCE(NULLIF(full_name,''), username) ASC
     LIMIT 200"
);

$csrf_token = Security::generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Leader Assignments</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">

<!-- Prevent sidebar collapse flash on load -->
<script>
    (function() {
        try {
            if (localStorage.getItem('sidebarCollapsed') === 'true') {
                document.documentElement.classList.add('sidebar-was-collapsed');
            }
        } catch (e) {}
    })();
</script>

<style>
* { box-sizing: border-box; }
body { background: #f0f2f5; font-family: 'Segoe UI', Tahoma, sans-serif; margin: 0; overflow: hidden; height: 100vh; }
#wrapper { display: flex; height: 100vh; overflow: hidden; }

/* ============================================================
   SIDEBAR
   ============================================================ */
#sidebar-wrapper {
    background: #1c1c1d;
    color: #fff;
    width: 250px;
    height: 100vh;
    flex-shrink: 0;
    overflow-y: auto;
    overflow-x: hidden;
    white-space: nowrap;
    transition: width 0.3s ease;
    box-shadow: 4px 0 10px rgba(0,0,0,0.1);
    position: relative;
    z-index: 1000;
}

#sidebar-wrapper.collapsed { width: 70px; }

/* Pre-paint collapsed state (before JS re-enables transitions) */
html.sidebar-was-collapsed #sidebar-wrapper { width: 70px; }
html.sidebar-was-collapsed #sidebar-wrapper .sidebar-heading .brand-text,
html.sidebar-was-collapsed #sidebar-wrapper .sidebar-heading .sidebar-logo,
html.sidebar-was-collapsed #sidebar-wrapper .list-group-item span,
html.sidebar-was-collapsed #sidebar-wrapper .badge {
    display: none;
}
html.sidebar-was-collapsed #sidebar-wrapper .list-group-item {
    text-align: center;
    padding: 15px 0;
    justify-content: center;
}
html.sidebar-was-collapsed #sidebar-wrapper .list-group-item i {
    margin-right: 0;
    width: 100%;
    text-align: center;
    font-size: 1.2rem;
}
html.sidebar-was-collapsed #sidebar-wrapper .sidebar-heading {
    justify-content: center;
    padding: 1rem 0;
    gap: 0;
}

/* Once JS re-enables transitions */
html.sidebar-transitions-on #sidebar-wrapper { transition: width 0.3s ease; }

/* ============================================================
   SIDEBAR HEADING — fixed layout
   ============================================================ */
#sidebar-wrapper .sidebar-heading {
    padding: 1rem 1rem .85rem;
    font-size: 1.1rem;
    font-weight: 700;
    border-bottom: 1px solid rgba(255,255,255,0.1);
    background: #1c1c1d;
    color: white;
    display: flex;
    align-items: center;
    gap: 10px;
    line-height: 1.15;
    flex-shrink: 0;
    min-width: 0;
}

#sidebar-wrapper .sidebar-heading .sidebar-logo {
    height: 34px;
    width: 34px;
    object-fit: contain;
    flex-shrink: 0;
    transition: all 0.3s ease;
}

#sidebar-wrapper .sidebar-heading .brand-text {
    display: flex;
    flex-direction: column;
    text-align: left;
    flex: 1 1 auto;
    min-width: 0;          /* Stops text from stretching */
    overflow: hidden;
}

#sidebar-wrapper .sidebar-heading .brand-text .brand-acronym {
    font-size: 1.15rem;
    letter-spacing: .5px;
    font-weight: 800;
    line-height: 1.1;
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
    overflow-wrap: break-word;
}

/* Toggle Button */
.menu-toggle {
    background: transparent;
    border: none;
    font-size: 1.5rem;
    color: white;
    cursor: pointer;
    padding: 0 5px;
    flex-shrink: 0;
    transition: transform 0.2s;
}
.menu-toggle:hover { color: #fff; transform: scale(1.1); }

/* COLLAPSED HEADING - Keep the toggle button visible! */
#sidebar-wrapper.collapsed .sidebar-heading {
    justify-content: center;
    padding: 1rem 0;
    gap: 0;
}
#sidebar-wrapper.collapsed .sidebar-heading .brand-text,
#sidebar-wrapper.collapsed .sidebar-heading .sidebar-logo {
    display: none;
}
/* Make sure the toggle button doesn't get hidden */
#sidebar-wrapper.collapsed .sidebar-heading .menu-toggle {
    margin-right: 0;
}

/* ============================================================
   SIDEBAR LIST
   ============================================================ */
#sidebar-wrapper .list-group-item {
    background: transparent;
    border: none;
    color: rgba(255,255,255,.9);
    padding: 0.8rem 1.2rem;
    display: flex;
    gap: 10px;
    align-items: center;
    text-decoration: none;
    font-weight: 500;
    font-size: .95rem;
    transition: background 0.2s, color 0.2s;
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

#sidebar-wrapper .list-group-item .badge {
    margin-left: auto;
    font-size: 0.65rem;
    padding: 3px 7px;
    line-height: 1;
    border-radius: 10px;
    flex-shrink: 0;
}

#sidebar-wrapper.collapsed .list-group-item {
    padding: 15px 0;
    text-align: center;
    justify-content: center;
}
#sidebar-wrapper.collapsed .list-group-item span { display: none; }
#sidebar-wrapper.collapsed .list-group-item i {
    margin-right: 0;
    width: 100%;
    text-align: center;
    font-size: 1.2rem;
}
#sidebar-wrapper.collapsed .badge { display: none !important; }
#sidebar-wrapper.collapsed .list-group-item:hover,
#sidebar-wrapper.collapsed .list-group-item.active {
    border-left: none;
    border-bottom: 2px solid #fff;
}

/* ============================================================
   MAIN CONTENT
   ============================================================ */
#page-content-wrapper { flex: 1; min-width: 0; padding: 0; height: 100vh; overflow-y: auto; }

.navbar {
    background: #fff;
    box-shadow: 0 2px 4px rgba(0,0,0,.05);
    padding: .7rem 1.2rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
    position: sticky;
    top: 0;
    z-index: 10;
}
.navbar-brand { font-weight: 600; color: #1c1c1d; margin: 0; font-size: 1.2rem; }

.cards-wrap { padding: 1.5rem; max-width: 1200px; margin: 0 auto; }

.panel { background: #fff; border-radius: 14px; box-shadow: 0 1px 3px rgba(0,0,0,.05); padding: 1.5rem; margin-bottom: 1.5rem; }
.panel h5 { margin: 0 0 1rem; font-weight: 700; font-size: 1rem; color: #1e2b3c; display: flex; align-items: center; gap: 10px; }
.panel h5 i { color: #2d5f92; }
.panel .hint { color: #64748b; font-size: .85rem; margin-bottom: 1rem; }

table { width: 100%; border-collapse: collapse; font-size: .9rem; }
table th { background: #f8fafc; text-align: left; padding: .75rem 1rem; font-size: .72rem; text-transform: uppercase; color: #64748b; font-weight: 700; border-bottom: 1px solid #e2e8f0; }
table td { padding: .75rem 1rem; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
table tr:last-child td { border-bottom: none; }

.btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; border-radius: 8px; font-size: .85rem; font-weight: 700; border: 1px solid transparent; cursor: pointer; text-decoration: none; transition: all .15s; }
.btn-primary { background: #1e3a5a; color: #fff; border-color: #1e3a5a; }
.btn-primary:hover { background: #2d5f92; color: #fff; }
.btn-danger { background: #dc2626; color: #fff; border-color: #dc2626; }
.btn-danger:hover { background: #b91c1c; }
.btn-sm { padding: 5px 12px; font-size: .78rem; }

.form-select, .form-control { padding: .6rem .85rem; border-radius: 8px; border: 1px solid #dee2e6; font-size: .9rem; width: 100%; }
.form-select:focus, .form-control:focus { border-color: #1e3a5a; box-shadow: 0 0 0 3px rgba(30,58,90,.12); outline: none; }

.alert { border-radius: 10px; padding: .85rem 1.15rem; font-size: .9rem; margin-bottom: 1rem; border-left: 4px solid; }
.alert-success { background: #dcfce7; color: #14532d; border-color: #16a34a; }
.alert-danger { background: #fdecee; color: #842029; border-color: #dc2626; }
.alert-info { background: #e8f0fe; color: #1e40af; border-color: #2d5f92; }

.empty { padding: 2rem; text-align: center; color: #94a3b8; font-size: .9rem; }
.empty i { font-size: 2rem; color: #cbd5e1; display: block; margin-bottom: .5rem; }

@media (max-width: 768px) {
    #sidebar-wrapper { position: fixed; left: 0; top: 0; transform: translateX(-100%); z-index: 1050; }
    #sidebar-wrapper.mobile-open { transform: translateX(0); }
    .menu-toggle { display: inline-block; }
}
</style>
</head>
<body>
<div id="wrapper">

    <!-- Sidebar -->
    <div id="sidebar-wrapper">
        <div class="sidebar-heading">
            <img src="../assets/images/harana-logo.png" alt="NHGL" class="sidebar-logo" onerror="this.style.display='none';">
            <span class="brand-text">
                <span class="brand-acronym">NHGL</span>
                <span class="brand-full">Nagkaisang Haranista sa Gintong Luzon</span>
            </span>
            <button class="menu-toggle" id="menuToggle" aria-label="Toggle sidebar">
                <i class="fas fa-bars"></i>
            </button>
        </div>
        <div class="list-group list-group-flush mt-2">
            <a href="dashboard.php" class="list-group-item"><i class="fas fa-tachometer-alt"></i><span>Dashboard</span></a>
            <a href="members.php" class="list-group-item"><i class="fas fa-users"></i><span>Members</span></a>
            <a href="manage_chapters.php" class="list-group-item"><i class="fas fa-sitemap"></i><span>Chapters &amp; Groups</span></a>
            <a href="leader_assignments.php" class="list-group-item active"><i class="fas fa-user-shield"></i><span>Leader Assignment</span></a>
            <a href="council.php" class="list-group-item"><i class="fas fa-user-tie"></i><span>Council</span></a>
            <a href="payments.php" class="list-group-item"><i class="fas fa-credit-card"></i><span>Payments</span></a>
            <a href="remittances.php" class="list-group-item"><i class="fas fa-hand-holding-usd"></i><span>Remittances</span></a>
            <a href="reports.php" class="list-group-item"><i class="fas fa-chart-bar"></i><span>Reports</span></a>
            <a href="announcements.php" class="list-group-item"><i class="fas fa-bullhorn"></i><span>Send Announcement</span></a>
            <a href="pending_users.php" class="list-group-item">
                <i class="fas fa-user-clock"></i><span>Pending</span>
                <?php if ($pending_count): ?><span class="badge bg-danger"><?php echo $pending_count; ?></span><?php endif; ?>
            </a>
            <a href="settings.php" class="list-group-item"><i class="fas fa-cog"></i><span>Settings</span></a>
            <a href="../logout.php" class="list-group-item"><i class="fas fa-sign-out-alt"></i><span>Logout</span></a>
        </div>
    </div>

    <!-- Main -->
    <div id="page-content-wrapper">
        <nav class="navbar">
            <span class="navbar-brand"><i class="fas fa-user-shield me-2"></i>Leader Group Assignments</span>
            <span class="text-muted small"><i class="fas fa-user-circle me-1"></i><?php echo htmlspecialchars($current_user['full_name'] ?? $current_user['username']); ?></span>
        </nav>

        <div class="cards-wrap">

            <?php if ($msg): ?><div class="alert alert-success"><i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($msg); ?></div><?php endif; ?>
            <?php if ($err): ?><div class="alert alert-danger"><i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($err); ?></div><?php endif; ?>

            <!-- ============ 1. Assign leader to group ============ -->
            <div class="panel">
                <h5><i class="fas fa-link"></i> Step 1 — Assign a leader to a group</h5>
                <?php if (empty($leaders)): ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        No leaders exist yet. Use <strong>Step 3</strong> below to promote a user, then come back here.
                    </div>
                <?php else: ?>
                    <form method="POST" class="row g-3">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="action" value="assign">
                        <div class="col-md-5">
                            <label class="form-label small fw-bold">Leader</label>
                            <select name="leader_user_id" class="form-select" required>
                                <option value="">— Select leader —</option>
                                <?php foreach ($leaders as $l): ?>
                                    <option value="<?php echo (int)$l['user_id']; ?>">
                                        <?php echo htmlspecialchars($l['full_name'] ?: $l['username']); ?>
                                        (<?php echo htmlspecialchars($l['username']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label small fw-bold">Group</label>
                            <select name="group_id" class="form-select" required>
                                <option value="">— Select group —</option>
                                <?php foreach ($all_groups as $g): ?>
                                    <option value="<?php echo (int)$g['group_id']; ?>">
                                        <?php echo htmlspecialchars($g['chapter_name']); ?> → <?php echo htmlspecialchars($g['group_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2 d-flex align-items-end">
                            <button class="btn btn-primary w-100" type="submit">
                                <i class="fas fa-link"></i> Assign
                            </button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>

            <!-- ============ 2. Current assignments ============ -->
            <div class="panel">
                <h5><i class="fas fa-list"></i> Step 2 — Current assignments (<?php echo count($assignments); ?>)</h5>
                <?php if (empty($assignments)): ?>
                    <div class="empty">
                        <i class="fas fa-unlink"></i>
                        No assignments yet. A leader can only see members of groups you assign them to.
                    </div>
                <?php else: ?>
                    <div style="overflow-x:auto;">
                        <table>
                            <thead>
                                <tr>
                                    <th>Leader</th>
                                    <th>Username</th>
                                    <th>Chapter</th>
                                    <th>Group</th>
                                    <th>Assigned</th>
                                    <th style="text-align:right;"></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($assignments as $a): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($a['leader_name'] ?: $a['username']); ?></strong></td>
                                    <td><code style="font-size:.78rem;"><?php echo htmlspecialchars($a['username']); ?></code></td>
                                    <td><?php echo htmlspecialchars($a['chapter_name']); ?></td>
                                    <td><?php echo htmlspecialchars($a['group_name']); ?></td>
                                    <td><small class="text-muted"><?php echo date('M d, Y', strtotime($a['assigned_at'])); ?></small></td>
                                    <td style="text-align:right;">
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Remove this assignment?');">
                                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                            <input type="hidden" name="action" value="unassign">
                                            <input type="hidden" name="assignment_id" value="<?php echo (int)$a['assignment_id']; ?>">
                                            <button class="btn btn-danger btn-sm"><i class="fas fa-unlink"></i> Remove</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <!-- ============ 3. Promote viewer/treasurer to leader ============ -->
            <div class="panel">
                <h5><i class="fas fa-user-plus"></i> Step 3 — Promote a user to leader</h5>
                <div class="hint">Users with role <code>viewer</code> or <code>treasurer</code> can be promoted. After promoting, they appear in Step 1's dropdown.</div>

                <?php if (empty($candidates)): ?>
                    <div class="alert alert-info mb-0">
                        <i class="fas fa-info-circle me-2"></i>
                        No eligible users to promote right now.
                    </div>
                <?php else: ?>
                    <form method="POST" class="row g-3">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="action" value="promote_user">
                        <div class="col-md-9">
                            <label class="form-label small fw-bold">User</label>
                            <select name="user_id" class="form-select" required>
                                <option value="">— Select user —</option>
                                <?php foreach ($candidates as $c): ?>
                                    <option value="<?php echo (int)$c['user_id']; ?>">
                                        <?php echo htmlspecialchars($c['full_name'] ?: $c['username']); ?>
                                        — <?php echo htmlspecialchars($c['email'] ?? ''); ?>
                                        [<?php echo htmlspecialchars($c['role']); ?>]
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3 d-flex align-items-end">
                            <button class="btn btn-primary w-100" type="submit">
                                <i class="fas fa-arrow-up"></i> Promote to leader
                            </button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>

            <!-- ============ 4. Demote / manage leaders ============ -->
            <?php if (!empty($leaders)): ?>
            <div class="panel">
                <h5><i class="fas fa-users-cog"></i> Step 4 — Manage existing leaders (<?php echo count($leaders); ?>)</h5>
                <div class="hint">Demoting a leader to viewer removes all their group assignments. They will no longer be able to log in as a leader.</div>
                <div style="overflow-x:auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>Full name</th>
                                <th>Username</th>
                                <th>Email</th>
                                <th style="text-align:right;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($leaders as $l): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($l['full_name'] ?: '—'); ?></strong></td>
                                <td><code style="font-size:.78rem;"><?php echo htmlspecialchars($l['username']); ?></code></td>
                                <td><small><?php echo htmlspecialchars($l['email'] ?? '—'); ?></small></td>
                                <td style="text-align:right;">
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Demote this leader? All their assignments will be removed.');">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                        <input type="hidden" name="action" value="demote_leader">
                                        <input type="hidden" name="user_id" value="<?php echo (int)$l['user_id']; ?>">
                                        <button class="btn btn-danger btn-sm"><i class="fas fa-arrow-down"></i> Demote</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

        </div>
    </div>
</div>

<script>
// ========================================
// SIDEBAR TOGGLE + PERSISTENCE (no-flash)
// ========================================
(function() {
    var sidebar    = document.getElementById('sidebar-wrapper');
    var menuToggle = document.getElementById('menuToggle');

    if (!sidebar || !menuToggle) return;

    // Apply persisted state
    if (localStorage.getItem('sidebarCollapsed') === 'true') {
        sidebar.classList.add('collapsed');
    }

    // After first paint, swap from "pre-paint" CSS to normal transitions
    requestAnimationFrame(function() {
        requestAnimationFrame(function() {
            document.documentElement.classList.remove('sidebar-was-collapsed');
            document.documentElement.classList.add('sidebar-transitions-on');
        });
    });

    menuToggle.addEventListener('click', function() {
        sidebar.classList.toggle('collapsed');
        localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
    });
})();
</script>
</body>
</html>