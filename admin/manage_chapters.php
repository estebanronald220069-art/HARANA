<?php
// admin/manage_chapters.php
// Manage Chapters & Groups — single page, two tabs, full CRUD
require_once '../includes/config.php';
require_once '../includes/db_connection.php';
require_once '../includes/security.php';
require_once '../includes/auth.php';

$auth->requireLogin();
$auth->requireRole('admin');           // only admins
$current_user = $auth->getCurrentUser();
$db = Database::getInstance();

// Pending badge count for sidebar
$pending_count = $db->getSingle(
    "SELECT COUNT(*) as cnt FROM pending_users WHERE status = 'pending'"
)['cnt'] ?? 0;

$message = '';
$error   = '';

// ============================================================
// Handle POST actions
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please refresh.';
    } else {
        $action = $_POST['action'] ?? '';

        // ----- CHAPTERS -----
        if ($action === 'add_chapter') {
            $name = Security::sanitize($_POST['chapter_name'] ?? '');
            $desc = Security::sanitize($_POST['description'] ?? '');
            $sort = (int)($_POST['sort_order'] ?? 0);

            if ($name === '') {
                $error = 'Chapter name is required.';
            } else {
                $exists = $db->getSingle(
                    "SELECT chapter_id FROM chapters WHERE chapter_name = ?",
                    [$name], 's'
                );
                if ($exists) {
                    $error = 'That chapter already exists.';
                } else {
                    $ok = $db->execute(
                        "INSERT INTO chapters (chapter_name, description, sort_order)
                         VALUES (?, ?, ?)",
                        [$name, $desc, $sort], 'ssi'
                    );
                    if ($ok) {
                        Security::logEvent('CHAPTER_ADD', "Added chapter: $name");
                        $message = "Chapter '{$name}' added.";
                    } else {
                        $error = 'Failed to add chapter.';
                    }
                }
            }
        }

        elseif ($action === 'edit_chapter') {
            $id   = (int)($_POST['chapter_id'] ?? 0);
            $name = Security::sanitize($_POST['chapter_name'] ?? '');
            $desc = Security::sanitize($_POST['description'] ?? '');
            $sort = (int)($_POST['sort_order'] ?? 0);
            $act  = isset($_POST['is_active']) ? 1 : 0;

            if ($id <= 0 || $name === '') {
                $error = 'Invalid chapter data.';
            } else {
                $dup = $db->getSingle(
                    "SELECT chapter_id FROM chapters WHERE chapter_name = ? AND chapter_id != ?",
                    [$name, $id], 'si'
                );
                if ($dup) {
                    $error = 'Another chapter already uses that name.';
                } else {
                    $ok = $db->execute(
                        "UPDATE chapters
                         SET chapter_name = ?, description = ?, sort_order = ?, is_active = ?
                         WHERE chapter_id = ?",
                        [$name, $desc, $sort, $act, $id], 'ssiii'
                    );
                    if ($ok !== false) {
                        Security::logEvent('CHAPTER_EDIT', "Edited chapter ID $id");
                        $message = 'Chapter updated.';
                    } else {
                        $error = 'Failed to update chapter.';
                    }
                }
            }
        }

        elseif ($action === 'delete_chapter') {
            $id = (int)($_POST['chapter_id'] ?? 0);
            // Refuse if groups still reference it
            $groupCount = $db->getSingle(
                "SELECT COUNT(*) as cnt FROM groups WHERE chapter_id = ?",
                [$id], 'i'
            )['cnt'] ?? 0;

            if ($groupCount > 0) {
                $error = "Cannot delete: {$groupCount} group(s) still belong to this chapter. "
                       . "Delete or move them first.";
            } else {
                $ok = $db->execute("DELETE FROM chapters WHERE chapter_id = ?", [$id], 'i');
                if ($ok) {
                    Security::logEvent('CHAPTER_DELETE', "Deleted chapter ID $id");
                    $message = 'Chapter deleted.';
                } else {
                    $error = 'Failed to delete chapter.';
                }
            }
        }

        // ----- GROUPS -----
        elseif ($action === 'add_group') {
            $name      = Security::sanitize($_POST['group_name'] ?? '');
            $chapterId = (int)($_POST['chapter_id'] ?? 0);
            $desc      = Security::sanitize($_POST['description'] ?? '');

            if ($name === '' || $chapterId <= 0) {
                $error = 'Group name and parent chapter are required.';
            } else {
                $chapterExists = $db->getSingle(
                    "SELECT chapter_id FROM chapters WHERE chapter_id = ?",
                    [$chapterId], 'i'
                );
                if (!$chapterExists) {
                    $error = 'Parent chapter not found.';
                } else {
                    $dup = $db->getSingle(
                        "SELECT group_id FROM groups WHERE group_name = ? AND chapter_id = ?",
                        [$name, $chapterId], 'si'
                    );
                    if ($dup) {
                        $error = "Group '{$name}' already exists under that chapter.";
                    } else {
                        $ok = $db->execute(
                            "INSERT INTO groups (group_name, chapter_id, description)
                             VALUES (?, ?, ?)",
                            [$name, $chapterId, $desc], 'sis'
                        );
                        if ($ok) {
                            Security::logEvent('GROUP_ADD', "Added group: $name (chapter $chapterId)");
                            $message = "Group '{$name}' added.";
                        } else {
                            $error = 'Failed to add group.';
                        }
                    }
                }
            }
        }

        elseif ($action === 'edit_group') {
            $id        = (int)($_POST['group_id'] ?? 0);
            $name      = Security::sanitize($_POST['group_name'] ?? '');
            $chapterId = (int)($_POST['chapter_id'] ?? 0);
            $desc      = Security::sanitize($_POST['description'] ?? '');
            $act       = isset($_POST['is_active']) ? 1 : 0;

            if ($id <= 0 || $name === '' || $chapterId <= 0) {
                $error = 'Invalid group data.';
            } else {
                $ok = $db->execute(
                    "UPDATE groups
                     SET group_name = ?, chapter_id = ?, description = ?, is_active = ?
                     WHERE group_id = ?",
                    [$name, $chapterId, $desc, $act, $id], 'sisii'
                );
                if ($ok !== false) {
                    Security::logEvent('GROUP_EDIT', "Edited group ID $id");
                    $message = 'Group updated.';
                } else {
                    $error = 'Failed to update group.';
                }
            }
        }

        elseif ($action === 'delete_group') {
            $id = (int)($_POST['group_id'] ?? 0);
            $ok = $db->execute("DELETE FROM groups WHERE group_id = ?", [$id], 'i');
            if ($ok) {
                Security::logEvent('GROUP_DELETE', "Deleted group ID $id");
                $message = 'Group deleted.';
            } else {
                $error = 'Failed to delete group.';
            }
        }
    }
}

// ============================================================
// Fetch data for rendering
// ============================================================
$chapters = $db->getAll(
    "SELECT c.*,
            (SELECT COUNT(*) FROM groups g WHERE g.chapter_id = c.chapter_id) AS group_count
     FROM chapters c
     ORDER BY c.sort_order ASC, c.chapter_name ASC"
);

$groups = $db->getAll(
    "SELECT g.*, c.chapter_name
     FROM groups g
     JOIN chapters c ON c.chapter_id = g.chapter_id
     ORDER BY c.chapter_name ASC, g.group_name ASC"
);

// For the "edit group" dropdown and add form
$activeChapters = $db->getAll(
    "SELECT chapter_id, chapter_name FROM chapters WHERE is_active = 1
     ORDER BY sort_order ASC, chapter_name ASC"
);

$csrf_token = Security::generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Chapters & Groups - <?php echo APP_NAME; ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
<style>
  * { box-sizing: border-box; }
  body { background:#f0f2f5; font-family:'Segoe UI',Tahoma,sans-serif; margin:0; padding:0; }
  #wrapper { display:flex; min-height:100vh; }

  /* ===== SIDEBAR (dark theme matching dashboard.php) ===== */
  #sidebar-wrapper {
    background:#1c1c1d; color:#fff; width:250px; min-height:100vh;
    transition:width .3s; flex-shrink:0;
  }
  #sidebar-wrapper.collapsed { width:70px; }
  #sidebar-wrapper.collapsed .list-group-item span { display:none; }
  #sidebar-wrapper.collapsed .list-group-item {
    text-align:center;
    padding:15px 0;
    justify-content:center;
  }
  #sidebar-wrapper.collapsed .list-group-item i {
    margin-right:0;
    width:100%;
    text-align:center;
    font-size:1.2rem;
  }

  #sidebar-wrapper .sidebar-heading {
    padding:1rem 1rem .85rem; font-size:1.1rem; font-weight:700;
    border-bottom:1px solid rgba(255,255,255,.1);
    display:flex; align-items:center; gap:10px;
    line-height:1.15;
    background:#1c1c1d;
    color:#fff;
  }
  #sidebar-wrapper .sidebar-heading .sidebar-logo {
    height:34px; width:34px; object-fit:contain; flex-shrink:0;
  }
  #sidebar-wrapper .sidebar-heading .brand-text {
    display:flex; flex-direction:column; text-align:left;
    flex:1; min-width:0;
  }
  #sidebar-wrapper .sidebar-heading .brand-text .brand-acronym {
    font-size:1.15rem;
    letter-spacing:.5px;
    font-weight:800;
  }
  #sidebar-wrapper .sidebar-heading .brand-text .brand-full {
    font-size:.62rem;
    font-weight:400;
    color:rgba(255,255,255,.7);
    letter-spacing:.2px;
    text-transform:uppercase;
    margin-top:1px;
    white-space:normal;
    line-height:1.15;
    max-width:175px;
  }

  /* Collapsed: hide the sidebar logo AND brand text — the logo "moves" to the navbar */
  #sidebar-wrapper.collapsed .sidebar-heading .brand-text { display:none; }
  #sidebar-wrapper.collapsed .sidebar-heading .sidebar-logo { display:none; }
  #sidebar-wrapper.collapsed .sidebar-heading {
    justify-content:center;
    padding:1rem 0;
    gap:0;
  }

  #sidebar-wrapper .list-group-item {
    background:transparent; border:none; color:rgba(255,255,255,.9);
    padding:.8rem 1.2rem; display:flex; align-items:center; gap:10px;
    font-weight:500;
    transition:all .2s;
    font-size:.95rem;
    text-align:left;
  }
  #sidebar-wrapper .list-group-item i {
    width:24px;
    text-align:center;
    font-size:1rem;
    flex-shrink:0;
  }
  #sidebar-wrapper .list-group-item:hover,
  #sidebar-wrapper .list-group-item.active {
    background:rgba(255,255,255,.15); color:#fff; border-left:4px solid #fff;
  }
  #sidebar-wrapper.collapsed .list-group-item:hover,
  #sidebar-wrapper.collapsed .list-group-item.active {
    border-left:none;
    border-bottom:2px solid #fff;
  }
  #sidebar-wrapper .list-group-item .badge-count {
    margin-left:auto;
    font-size:.65rem;
    padding:3px 7px;
    line-height:1;
    border-radius:10px;
    flex-shrink:0;
  }
  #sidebar-wrapper.collapsed .badge-count { display:none !important; }

  /* Menu toggle — larger to match dashboard.php */
  .menu-toggle {
    background:transparent; border:none; color:white;
    font-size:1.5rem;
    cursor:pointer;
    padding:0 10px;
    flex-shrink:0;
    transition:all .2s;
  }
  .menu-toggle:hover { color:#fff; transform:scale(1.1); }

  /* ===== MAIN CONTENT ===== */
  #page-content-wrapper { flex:1; background:#f0f2f5; min-width:0; }

  .navbar { background:#fff !important; box-shadow:0 2px 4px rgba(0,0,0,.05); }
  .navbar .navbar-brand { color:#1c1c1d !important; }

  /* Header logo — appears beside the page title ONLY when sidebar is collapsed */
  .header-logo {
    height: 28px;
    width: auto;
    margin-right: 8px;
    vertical-align: middle;
    display: none;
  }
  #sidebar-wrapper.collapsed ~ #page-content-wrapper .header-logo {
    display: inline-block;
  }
  .navbar-left {
    display: flex;
    align-items: center;
    gap: 8px;
  }

  .card { border:none; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,.06); }
  .card-header {
    background:#1c1c1d;
    color:#fff; border-radius:12px 12px 0 0 !important;
  }

  .nav-tabs .nav-link { color:#495057; font-weight:500; }
  .nav-tabs .nav-link.active { color:#1c1c1d; font-weight:600; }

  .badge-count { font-size:.7rem; }
  .table td, .table th { vertical-align:middle; }
  .chapter-row:hover, .group-row:hover { background:#f8f9fa; }
  .btn-action { padding:2px 8px; font-size:.75rem; }

  /* ===== Scrollable list ===== */
  .scroll-list {
    max-height: 48vh;
    overflow-y: auto;
    overflow-x: hidden;
    border-radius: 0 0 12px 12px;
  }
  .scroll-list table { margin-bottom: 0; }
  .scroll-list thead th {
    position: sticky;
    top: 0;
    z-index: 3;
    background: #f1f3f5;
    box-shadow: inset 0 -1px 0 #dee2e6;
  }
  .scroll-list::-webkit-scrollbar { width: 8px; }
  .scroll-list::-webkit-scrollbar-track { background: #eef1f5; }
  .scroll-list::-webkit-scrollbar-thumb { background: #b9c4d1; border-radius: 4px; }
  .scroll-list::-webkit-scrollbar-thumb:hover { background: #96a5b7; }

  /* Compact add-forms */
  .add-form-card .card-body { padding: 1rem 1rem .75rem; }
  .add-form-card .mb-3 { margin-bottom: .65rem !important; }

  /* Search bar */
  .search-bar-wrap {
    background: #f8f9fb;
    border-bottom: 1px solid #e6e9ee;
    padding: .6rem 1rem;
  }
  .search-bar-wrap .input-group-text {
    background: #fff;
    border-right: 0;
    color: #6c757d;
  }
  .search-bar-wrap .form-control {
    border-left: 0;
  }
  .search-bar-wrap .form-control:focus {
    box-shadow: none;
    border-color: #ced4da;
  }
  .search-bar-wrap .input-group:focus-within .input-group-text,
  .search-bar-wrap .input-group:focus-within .form-control {
    border-color: #1c1c1d;
  }
  .no-result-row td {
    text-align: center;
    color: #6c757d;
    padding: 1.5rem;
  }

  /* Dark primary buttons */
  .btn-primary {
    background:#1c1c1d;
    border-color:#1c1c1d;
  }
  .btn-primary:hover,
  .btn-primary:focus,
  .btn-primary:active {
    background:#2c2c2d !important;
    border-color:#2c2c2d !important;
  }
  .btn-outline-primary {
    color:#1c1c1d;
    border-color:#1c1c1d;
  }
  .btn-outline-primary:hover {
    background:#1c1c1d;
    border-color:#1c1c1d;
    color:#fff;
  }

  /* Mobile */
  @media (max-width: 768px) {
    #sidebar-wrapper {
      position: fixed;
      left: 0; top: 0;
      transform: translateX(-100%);
      z-index: 1050;
      transition: transform .3s ease;
    }
    #sidebar-wrapper.mobile-open { transform: translateX(0); }
    .header-logo { display: inline-block !important; }
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
      <button class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></button>
    </div>
    <div class="list-group list-group-flush mt-2">
      <a href="dashboard.php" class="list-group-item"><i class="fas fa-tachometer-alt"></i><span>Dashboard</span></a>
      <a href="members.php" class="list-group-item"><i class="fas fa-users"></i><span>Members</span></a>
      <a href="manage_chapters.php" class="list-group-item active"><i class="fas fa-sitemap"></i><span>Chapters &amp; Groups</span></a>
     <a href="leader_assignments.php" class="list-group-item <?php echo $current_page == 'leader_assignments.php' ? 'active' : ''; ?>"><i class="fas fa-user-shield"></i><span>Leader Assignment</span></a>
      <a href="council.php" class="list-group-item"><i class="fas fa-user-tie"></i><span>Council</span></a>
      <a href="payments.php" class="list-group-item"><i class="fas fa-credit-card"></i><span>Payments</span></a>
      <a href="remittances.php" class="list-group-item"><i class="fas fa-hand-holding-usd"></i><span>Remittances</span></a>
      <a href="reports.php" class="list-group-item"><i class="fas fa-chart-bar"></i><span>Reports</span></a>
       <a href="announcements.php" class="list-group-item"><i class="fas fa-bullhorn"></i><span>Send Announcement</span></a>

      <a href="pending_users.php" class="list-group-item">
        <i class="fas fa-user-clock"></i><span>Pending</span>
        <?php if ($pending_count > 0): ?>
          <span class="badge bg-danger badge-count"><?php echo $pending_count; ?></span>
        <?php endif; ?>
      </a>
      <a href="settings.php" class="list-group-item"><i class="fas fa-cog"></i><span>Settings</span></a>
      <a href="../logout.php" class="list-group-item" onclick="return confirm('Logout?');">
        <i class="fas fa-sign-out-alt"></i><span>Logout</span>
      </a>
    </div>
  </div>

  <!-- Main -->
  <div id="page-content-wrapper">
    <nav class="navbar navbar-light px-4 py-3">
      <div class="navbar-left">
        <img src="../assets/images/harana-logo.png" alt="Harana" class="header-logo" id="headerLogo" onerror="this.style.display='none';">
        <span class="navbar-brand mb-0 h1">
          <i class="fas fa-sitemap me-2"></i>Chapters &amp; Groups
        </span>
      </div>
      <span class="small">
        <i class="fas fa-user-circle me-2"></i>
        <?php echo htmlspecialchars($current_user['full_name'] ?? $current_user['username']); ?>
      </span>
    </nav>

    <div class="container-fluid p-4">
      <?php if ($message): ?>
        <div class="alert alert-success alert-dismissible fade show">
          <?php echo htmlspecialchars($message); ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
      <?php endif; ?>
      <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show">
          <?php echo htmlspecialchars($error); ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
      <?php endif; ?>

      <ul class="nav nav-tabs mb-3" id="mainTabs" role="tablist">
        <li class="nav-item" role="presentation">
          <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-chapters" type="button">
            <i class="fas fa-map-marker-alt me-1"></i>Chapters
            <span class="badge bg-secondary ms-1"><?php echo count($chapters); ?></span>
          </button>
        </li>
        <li class="nav-item" role="presentation">
          <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-groups" type="button">
            <i class="fas fa-layer-group me-1"></i>Groups
            <span class="badge bg-secondary ms-1"><?php echo count($groups); ?></span>
          </button>
        </li>
      </ul>

      <div class="tab-content">
        <!-- ============ CHAPTERS TAB ============ -->
        <div class="tab-pane fade show active" id="tab-chapters">

          <div class="card add-form-card mb-3">
            <div class="card-header"><i class="fas fa-plus me-2"></i>Add Chapter</div>
            <div class="card-body">
              <form method="POST" class="row g-2 align-items-end">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="action" value="add_chapter">

                <div class="col-md-4">
                  <label class="form-label mb-1">Chapter Name <span class="text-danger">*</span></label>
                  <input type="text" name="chapter_name" class="form-control"
                         placeholder="e.g., NATIVIDAD" required>
                </div>
                <div class="col-md-4">
                  <label class="form-label mb-1">Description</label>
                  <input type="text" name="description" class="form-control" placeholder="Optional">
                </div>
                <div class="col-md-2">
                  <label class="form-label mb-1">Sort Order</label>
                  <input type="number" name="sort_order" class="form-control" value="0">
                </div>
                <div class="col-md-2">
                  <button class="btn btn-primary w-100">
                    <i class="fas fa-save me-1"></i>Add Chapter
                  </button>
                </div>
              </form>
            </div>
          </div>

          <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
              <span><i class="fas fa-list me-2"></i>All Chapters</span>
              <span class="badge bg-light text-dark" id="chapterCountBadge"><?php echo count($chapters); ?> total</span>
            </div>

            <div class="search-bar-wrap">
              <div class="input-group input-group-sm">
                <span class="input-group-text"><i class="fas fa-search"></i></span>
                <input type="text" id="chapterSearch" class="form-control"
                       placeholder="Search chapter name, description, or status…"
                       autocomplete="off">
                <button class="btn btn-outline-secondary" type="button" id="chapterSearchClear" title="Clear">
                  <i class="fas fa-times"></i>
                </button>
              </div>
            </div>

            <div class="scroll-list">
              <div class="table-responsive">
                <table class="table table-hover mb-0" id="chapterTable">
                  <thead class="table-light">
                    <tr>
                      <th style="width:70px;">#</th>
                      <th>Name</th>
                      <th style="width:90px;">Groups</th>
                      <th style="width:110px;">Status</th>
                      <th style="width:120px;">Actions</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php if (empty($chapters)): ?>
                      <tr><td colspan="5" class="text-center text-muted py-4">No chapters yet.</td></tr>
                    <?php else: foreach ($chapters as $c): ?>
                      <tr class="chapter-row"
                          data-search="<?php echo htmlspecialchars(strtolower(
                              $c['chapter_name'] . ' ' .
                              ($c['description'] ?? '') . ' ' .
                              ($c['is_active'] ? 'active' : 'inactive')
                          ), ENT_QUOTES); ?>">
                        <td><?php echo $c['sort_order']; ?></td>
                        <td>
                          <strong><?php echo htmlspecialchars($c['chapter_name']); ?></strong>
                          <?php if ($c['description']): ?>
                            <br><small class="text-muted"><?php echo htmlspecialchars($c['description']); ?></small>
                          <?php endif; ?>
                        </td>
                        <td><span class="badge bg-info"><?php echo $c['group_count']; ?></span></td>
                        <td>
                          <?php if ($c['is_active']): ?>
                            <span class="badge bg-success">Active</span>
                          <?php else: ?>
                            <span class="badge bg-secondary">Inactive</span>
                          <?php endif; ?>
                        </td>
                        <td>
                          <button class="btn btn-sm btn-outline-primary btn-action"
                                  onclick='openEditChapter(<?php echo json_encode($c, JSON_HEX_APOS|JSON_HEX_QUOT); ?>)'>
                            <i class="fas fa-edit"></i>
                          </button>
                          <form method="POST" class="d-inline"
                                onsubmit="return confirm('Delete chapter <?php echo htmlspecialchars($c['chapter_name'], ENT_QUOTES); ?>?');">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                            <input type="hidden" name="action" value="delete_chapter">
                            <input type="hidden" name="chapter_id" value="<?php echo $c['chapter_id']; ?>">
                            <button class="btn btn-sm btn-outline-danger btn-action">
                              <i class="fas fa-trash"></i>
                            </button>
                          </form>
                        </td>
                      </tr>
                    <?php endforeach; endif; ?>
                    <tr class="no-result-row" id="chapterNoResult" style="display:none;">
                      <td colspan="5"><i class="fas fa-search me-1"></i>No matching chapters found.</td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          </div>

        </div>

        <!-- ============ GROUPS TAB ============ -->
        <div class="tab-pane fade" id="tab-groups">

          <div class="card add-form-card mb-3">
            <div class="card-header"><i class="fas fa-plus me-2"></i>Add Group</div>
            <div class="card-body">
              <form method="POST" class="row g-2 align-items-end">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="action" value="add_group">

                <div class="col-md-4">
                  <label class="form-label mb-1">Parent Chapter <span class="text-danger">*</span></label>
                  <select name="chapter_id" class="form-select" required>
                    <option value="">— Select chapter —</option>
                    <?php foreach ($activeChapters as $ac): ?>
                      <option value="<?php echo $ac['chapter_id']; ?>">
                        <?php echo htmlspecialchars($ac['chapter_name']); ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-md-4">
                  <label class="form-label mb-1">Group Name <span class="text-danger">*</span></label>
                  <input type="text" name="group_name" class="form-control"
                         placeholder="e.g., Group A" required>
                </div>
                <div class="col-md-2">
                  <label class="form-label mb-1">Description</label>
                  <input type="text" name="description" class="form-control" placeholder="Optional">
                </div>
                <div class="col-md-2">
                  <button class="btn btn-primary w-100">
                    <i class="fas fa-save me-1"></i>Add Group
                  </button>
                </div>
              </form>
            </div>
          </div>

          <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
              <span><i class="fas fa-list me-2"></i>All Groups</span>
              <span class="badge bg-light text-dark" id="groupCountBadge"><?php echo count($groups); ?> total</span>
            </div>

            <div class="search-bar-wrap">
              <div class="input-group input-group-sm">
                <span class="input-group-text"><i class="fas fa-search"></i></span>
                <input type="text" id="groupSearch" class="form-control"
                       placeholder="Search group name, chapter, description, or status…"
                       autocomplete="off">
                <button class="btn btn-outline-secondary" type="button" id="groupSearchClear" title="Clear">
                  <i class="fas fa-times"></i>
                </button>
              </div>
            </div>

            <div class="scroll-list">
              <div class="table-responsive">
                <table class="table table-hover mb-0" id="groupTable">
                  <thead class="table-light">
                    <tr>
                      <th style="width:180px;">Chapter</th>
                      <th>Group</th>
                      <th style="width:110px;">Status</th>
                      <th style="width:120px;">Actions</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php if (empty($groups)): ?>
                      <tr><td colspan="4" class="text-center text-muted py-4">No groups yet.</td></tr>
                    <?php else: foreach ($groups as $g): ?>
                      <tr class="group-row"
                          data-search="<?php echo htmlspecialchars(strtolower(
                              $g['group_name'] . ' ' .
                              $g['chapter_name'] . ' ' .
                              ($g['description'] ?? '') . ' ' .
                              ($g['is_active'] ? 'active' : 'inactive')
                          ), ENT_QUOTES); ?>">
                        <td><span class="badge bg-info"><?php echo htmlspecialchars($g['chapter_name']); ?></span></td>
                        <td>
                          <strong><?php echo htmlspecialchars($g['group_name']); ?></strong>
                          <?php if ($g['description']): ?>
                            <br><small class="text-muted"><?php echo htmlspecialchars($g['description']); ?></small>
                          <?php endif; ?>
                        </td>
                        <td>
                          <?php if ($g['is_active']): ?>
                            <span class="badge bg-success">Active</span>
                          <?php else: ?>
                            <span class="badge bg-secondary">Inactive</span>
                          <?php endif; ?>
                        </td>
                        <td>
                          <button class="btn btn-sm btn-outline-primary btn-action"
                                  onclick='openEditGroup(<?php echo json_encode($g, JSON_HEX_APOS|JSON_HEX_QUOT); ?>)'>
                            <i class="fas fa-edit"></i>
                          </button>
                          <form method="POST" class="d-inline"
                                onsubmit="return confirm('Delete group <?php echo htmlspecialchars($g['group_name'], ENT_QUOTES); ?>?');">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                            <input type="hidden" name="action" value="delete_group">
                            <input type="hidden" name="group_id" value="<?php echo $g['group_id']; ?>">
                            <button class="btn btn-sm btn-outline-danger btn-action">
                              <i class="fas fa-trash"></i>
                            </button>
                          </form>
                        </td>
                      </tr>
                    <?php endforeach; endif; ?>
                    <tr class="no-result-row" id="groupNoResult" style="display:none;">
                      <td colspan="4"><i class="fas fa-search me-1"></i>No matching groups found.</td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          </div>

        </div>
      </div>
    </div>
  </div>
</div>

<!-- ============ EDIT CHAPTER MODAL ============ -->
<div class="modal fade" id="editChapterModal" tabindex="-1">
  <div class="modal-dialog">
    <form method="POST" class="modal-content">
      <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
      <input type="hidden" name="action" value="edit_chapter">
      <input type="hidden" name="chapter_id" id="ec_id">
      <div class="modal-header bg-dark text-white">
        <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Edit Chapter</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label">Chapter Name *</label>
          <input type="text" name="chapter_name" id="ec_name" class="form-control" required>
        </div>
        <div class="mb-3">
          <label class="form-label">Description</label>
          <input type="text" name="description" id="ec_desc" class="form-control">
        </div>
        <div class="mb-3">
          <label class="form-label">Sort Order</label>
          <input type="number" name="sort_order" id="ec_sort" class="form-control">
        </div>
        <div class="form-check">
          <input type="checkbox" name="is_active" id="ec_active" class="form-check-input" value="1">
          <label class="form-check-label" for="ec_active">Active</label>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary">Save Changes</button>
      </div>
    </form>
  </div>
</div>

<!-- ============ EDIT GROUP MODAL ============ -->
<div class="modal fade" id="editGroupModal" tabindex="-1">
  <div class="modal-dialog">
    <form method="POST" class="modal-content">
      <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
      <input type="hidden" name="action" value="edit_group">
      <input type="hidden" name="group_id" id="eg_id">
      <div class="modal-header bg-dark text-white">
        <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Edit Group</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label">Parent Chapter *</label>
          <select name="chapter_id" id="eg_chapter" class="form-select" required>
            <?php foreach ($activeChapters as $ac): ?>
              <option value="<?php echo $ac['chapter_id']; ?>">
                <?php echo htmlspecialchars($ac['chapter_name']); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mb-3">
          <label class="form-label">Group Name *</label>
          <input type="text" name="group_name" id="eg_name" class="form-control" required>
        </div>
        <div class="mb-3">
          <label class="form-label">Description</label>
          <input type="text" name="description" id="eg_desc" class="form-control">
        </div>
        <div class="form-check">
          <input type="checkbox" name="is_active" id="eg_active" class="form-check-input" value="1">
          <label class="form-check-label" for="eg_active">Active</label>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary">Save Changes</button>
      </div>
    </form>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
  const sidebar = document.getElementById('sidebar-wrapper');
  const menuToggle = document.getElementById('menuToggle');

  // Restore collapsed state from localStorage
  if (localStorage.getItem('sidebarCollapsed') === 'true') {
    sidebar.classList.add('collapsed');
  }

  menuToggle.addEventListener('click', () => {
    sidebar.classList.toggle('collapsed');
    localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
  });

  function openEditChapter(c) {
    document.getElementById('ec_id').value   = c.chapter_id;
    document.getElementById('ec_name').value = c.chapter_name;
    document.getElementById('ec_desc').value = c.description || '';
    document.getElementById('ec_sort').value = c.sort_order;
    document.getElementById('ec_active').checked = c.is_active == 1;
    new bootstrap.Modal(document.getElementById('editChapterModal')).show();
  }

  function openEditGroup(g) {
    document.getElementById('eg_id').value      = g.group_id;
    document.getElementById('eg_name').value    = g.group_name;
    document.getElementById('eg_desc').value    = g.description || '';
    document.getElementById('eg_chapter').value = g.chapter_id;
    document.getElementById('eg_active').checked = g.is_active == 1;
    new bootstrap.Modal(document.getElementById('editGroupModal')).show();
  }

  // ============================================================
  // Live search — filters table rows as you type
  // ============================================================
  function attachSearch(inputId, clearBtnId, tableId, noResultId, countBadgeId, rowSelector) {
    const input       = document.getElementById(inputId);
    const clearBtn    = document.getElementById(clearBtnId);
    const noResultRow = document.getElementById(noResultId);
    const countBadge  = document.getElementById(countBadgeId);
    const rows        = Array.from(document.querySelectorAll('#' + tableId + ' tbody ' + rowSelector));

    function filter() {
      const q = input.value.trim().toLowerCase();
      let visible = 0;

      rows.forEach(row => {
        const haystack = row.dataset.search || '';
        const match = q === '' || haystack.indexOf(q) !== -1;
        row.style.display = match ? '' : 'none';
        if (match) visible++;
      });

      noResultRow.style.display = (visible === 0 && rows.length > 0) ? '' : 'none';
      countBadge.textContent = q === ''
        ? rows.length + ' total'
        : visible + ' of ' + rows.length + ' shown';
    }

    input.addEventListener('input', filter);
    input.addEventListener('keydown', e => {
      if (e.key === 'Escape') { input.value = ''; filter(); }
    });
    clearBtn.addEventListener('click', () => { input.value = ''; filter(); input.focus(); });

    filter();
  }

  attachSearch('chapterSearch', 'chapterSearchClear', 'chapterTable',
               'chapterNoResult', 'chapterCountBadge', '.chapter-row');

  attachSearch('groupSearch', 'groupSearchClear', 'groupTable',
               'groupNoResult', 'groupCountBadge', '.group-row');
</script>
</body>
</html>