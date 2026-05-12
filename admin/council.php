<?php
// admin/council.php - Council Members Management with History & Delete
require_once '../includes/config.php';
require_once '../includes/db_connection.php';
require_once '../includes/security.php';
require_once '../includes/auth.php';

$auth->requireLogin();
$current_user = $auth->getCurrentUser();

$db = Database::getInstance();
$message = '';
$error = '';

// Check for success message in URL
if (isset($_GET['success'])) {
    if ($_GET['success'] == 'added') {
        $message = 'Council member added successfully.';
    } elseif ($_GET['success'] == 'updated') {
        $message = 'Council member updated successfully.';
    } elseif ($_GET['success'] == 'deleted') {
        $message = 'Council member moved to deleted list.';
    } elseif ($_GET['success'] == 'permanently_deleted') {
        $message = 'Council member permanently deleted.';
    } elseif ($_GET['success'] == 'recovered') {
        $message = 'Council member recovered successfully.';
    } elseif ($_GET['success'] == 'undo_edit') {
        $message = 'Edit undone successfully.';
    } elseif ($_GET['success'] == 'photo_uploaded') {
        $message = 'Profile picture uploaded successfully.';
    } elseif ($_GET['success'] == 'photo_deleted') {
        $message = 'Profile picture deleted successfully.';
    }
}

// Get pending users count (for sidebar badge)
$pending_count = 0;
if ($current_user['role'] === 'admin') {
    $pending_count = $db->getSingle("SELECT COUNT(*) as cnt FROM pending_users WHERE status = 'pending'")['cnt'] ?? 0;
}

// Get active tab
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'active';

// Get counts for tabs
$active_count = $db->getSingle("SELECT COUNT(*) as total FROM council WHERE status = 'active' AND is_deleted = 0")['total'] ?? 0;
$inactive_count = $db->getSingle("SELECT COUNT(*) as total FROM council WHERE status = 'inactive' AND is_deleted = 0")['total'] ?? 0;
$edited_count = $db->getSingle("SELECT COUNT(DISTINCT council_id) as total FROM council_edit_history WHERE is_reverted = 0")['total'] ?? 0;
$deleted_count = $db->getSingle("SELECT COUNT(*) as total FROM council_deleted WHERE is_permanently_deleted = 0")['total'] ?? 0;

// Get council members based on tab
if ($active_tab === 'active') {
    $council_members = $db->getAll("SELECT * FROM council WHERE status = 'active' AND is_deleted = 0 ORDER BY created_at DESC");
} elseif ($active_tab === 'inactive') {
    $council_members = $db->getAll("SELECT * FROM council WHERE status = 'inactive' AND is_deleted = 0 ORDER BY created_at DESC");
} elseif ($active_tab === 'edited') {
    // Get members with edit history
    $council_members = $db->getAll("
        SELECT DISTINCT c.*, 
               (SELECT COUNT(*) FROM council_edit_history WHERE council_id = c.council_id AND is_reverted = 0) as edit_count,
               (SELECT MAX(edited_at) FROM council_edit_history WHERE council_id = c.council_id AND is_reverted = 0) as last_edited_at
        FROM council c
        WHERE EXISTS (SELECT 1 FROM council_edit_history WHERE council_id = c.council_id AND is_reverted = 0)
        AND c.is_deleted = 0
        ORDER BY last_edited_at DESC
    ");
} elseif ($active_tab === 'deleted') {
    // Get deleted members
    $council_members = $db->getAll("
        SELECT cd.*, cd.delete_id as original_id, cd.original_data
        FROM council_deleted cd
        WHERE cd.is_permanently_deleted = 0
        ORDER BY cd.deleted_at DESC
    ");
}

// Define position options
$positions = [
    'CEO/President' => 'CEO/President',
    'COO/Vice President' => 'COO/Vice President',
    'CFO/Treasurer' => 'CFO/Treasurer',
    'Book Keeper' => 'Book Keeper',
    'Corporate Secretary' => 'Corporate Secretary',
    'Supply Officer' => 'Supply Officer',
    'Internal Auditor' => 'Internal Auditor',
    'Monitoring Officer I' => 'Monitoring Officer I',
    'Monitoring Officer II' => 'Monitoring Officer II',
    'Liaison/Office Secretary' => 'Liaison/Office Secretary',
    'Encoder' => 'Encoder',
    'Over-all Adviser' => 'Over-all Adviser'
];

// Handle POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please refresh the page.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'add') {
            $last_name = Security::sanitize($_POST['last_name'] ?? '');
            $first_name = Security::sanitize($_POST['first_name'] ?? '');
            $middle_name = Security::sanitize($_POST['middle_name'] ?? '');
            $full_name = Security::sanitize($_POST['full_name'] ?? '');
            $position = Security::sanitize($_POST['position'] ?? '');
            $contact_number = Security::sanitize($_POST['contact_number'] ?? '');
            $email = Security::sanitize($_POST['email'] ?? '');
            $term_start = $_POST['term_start'] ?? null;
            $term_end = $_POST['term_end'] ?? null;

            if (empty($last_name) || empty($first_name) || empty($position)) {
                $error = 'Last name, first name, and position are required.';
            } else {
                $insert_sql = "INSERT INTO council (last_name, first_name, middle_name, full_name, position, contact_number, email, term_start, term_end, status, is_deleted, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', 0, ?)";
                $insert_params = [$last_name, $first_name, $middle_name, $full_name, $position, $contact_number, $email, $term_start, $term_end, $current_user['user_id']];
                $insert_types = 'sssssssssi';

                $result = $db->execute($insert_sql, $insert_params, $insert_types);
                if ($result) {
                    Security::logEvent('COUNCIL_ADD', "Added council member: $full_name");
                    header('Location: council.php?tab=active&success=added');
                    exit();
                } else {
                    $error = 'Failed to add council member.';
                }
            }
        } elseif ($action === 'edit') {
            $council_id = (int)$_POST['council_id'];
            
            // Get old data
            $old_data = $db->getSingle("SELECT * FROM council WHERE council_id = ?", [$council_id], 'i');
            
            $last_name = Security::sanitize($_POST['last_name'] ?? '');
            $first_name = Security::sanitize($_POST['first_name'] ?? '');
            $middle_name = Security::sanitize($_POST['middle_name'] ?? '');
            $full_name = Security::sanitize($_POST['full_name'] ?? '');
            $position = Security::sanitize($_POST['position'] ?? '');
            $contact_number = Security::sanitize($_POST['contact_number'] ?? '');
            $email = Security::sanitize($_POST['email'] ?? '');
            $term_start = $_POST['term_start'] ?? null;
            $term_end = $_POST['term_end'] ?? null;
            $status = Security::sanitize($_POST['status'] ?? 'active');

            if (empty($last_name) || empty($first_name) || empty($position)) {
                $error = 'Last name, first name, and position are required.';
            } else {
                $update_sql = "UPDATE council SET last_name=?, first_name=?, middle_name=?, full_name=?, position=?, contact_number=?, email=?, term_start=?, term_end=?, status=?, updated_by=? WHERE council_id=? AND is_deleted=0";
                $update_params = [$last_name, $first_name, $middle_name, $full_name, $position, $contact_number, $email, $term_start, $term_end, $status, $current_user['user_id'], $council_id];
                $update_types = 'ssssssssssii';

                $result = $db->execute($update_sql, $update_params, $update_types);
                if ($result !== false) {
                    // Track changes in history
                    $changes = [];
                    if ($old_data['last_name'] != $last_name) $changes[] = "Last Name: {$old_data['last_name']} → {$last_name}";
                    if ($old_data['first_name'] != $first_name) $changes[] = "First Name: {$old_data['first_name']} → {$first_name}";
                    if ($old_data['middle_name'] != $middle_name) $changes[] = "Middle Name: {$old_data['middle_name']} → {$middle_name}";
                    if ($old_data['position'] != $position) $changes[] = "Position: {$old_data['position']} → {$position}";
                    if ($old_data['contact_number'] != $contact_number) $changes[] = "Contact: {$old_data['contact_number']} → {$contact_number}";
                    if ($old_data['email'] != $email) $changes[] = "Email: {$old_data['email']} → {$email}";
                    if ($old_data['term_start'] != $term_start) $changes[] = "Term Start: {$old_data['term_start']} → {$term_start}";
                    if ($old_data['term_end'] != $term_end) $changes[] = "Term End: {$old_data['term_end']} → {$term_end}";
                    
                    foreach ($changes as $change) {
                        $db->execute(
                            "INSERT INTO council_edit_history (council_id, field_name, old_value, new_value, edited_by, edited_by_name) 
                             VALUES (?, ?, ?, ?, ?, ?)",
                            [$council_id, 'multiple', $old_data['full_name'], $change, $current_user['user_id'], $current_user['full_name']],
                            'isssis'
                        );
                    }
                    
                    Security::logEvent('COUNCIL_EDIT', "Edited council member ID: $council_id");
                    header('Location: council.php?tab=edited&success=updated');
                    exit();
                } else {
                    $error = 'Failed to update council member.';
                }
            }
        } elseif ($action === 'delete') {
            $council_id = (int)$_POST['council_id'];
            
            // Get member data
            $member = $db->getSingle("SELECT * FROM council WHERE council_id = ? AND is_deleted = 0", [$council_id], 'i');
            
            if ($member) {
                // Store in deleted table
                $original_data = json_encode($member);
                $result = $db->execute(
                    "INSERT INTO council_deleted (council_id, original_data, deleted_by, deleted_by_name) 
                     VALUES (?, ?, ?, ?)",
                    [$council_id, $original_data, $current_user['user_id'], $current_user['full_name']],
                    'isis'
                );
                
                if ($result) {
                    // Soft delete from main table
                    $db->execute("UPDATE council SET is_deleted = 1 WHERE council_id = ?", [$council_id], 'i');
                    Security::logEvent('COUNCIL_DELETE', "Deleted council member ID: $council_id");
                    header('Location: council.php?tab=deleted&success=deleted');
                    exit();
                }
            }
            $error = 'Failed to delete council member.';
            
        } elseif ($action === 'permanent_delete') {
            $delete_id = (int)$_POST['delete_id'];
            
            $deleted = $db->getSingle("SELECT * FROM council_deleted WHERE delete_id = ? AND is_permanently_deleted = 0", [$delete_id], 'i');
            
            if ($deleted) {
                $db->execute("UPDATE council_deleted SET is_permanently_deleted = 1 WHERE delete_id = ?", [$delete_id], 'i');
                Security::logEvent('COUNCIL_PERMANENT_DELETE', "Permanently deleted council member ID: {$deleted['council_id']}");
                header('Location: council.php?tab=deleted&success=permanently_deleted');
                exit();
            }
            $error = 'Failed to permanently delete.';
            
        } elseif ($action === 'recover') {
            $delete_id = (int)$_POST['delete_id'];
            
            $deleted = $db->getSingle("SELECT * FROM council_deleted WHERE delete_id = ? AND is_permanently_deleted = 0", [$delete_id], 'i');
            
            if ($deleted) {
                $original_data = json_decode($deleted['original_data'], true);
                
                // Update main table
                $db->execute(
                    "UPDATE council SET is_deleted = 0, status = 'active', updated_by = ? WHERE council_id = ?",
                    [$current_user['user_id'], $deleted['council_id']],
                    'ii'
                );
                
                // Remove from deleted table
                $db->execute("DELETE FROM council_deleted WHERE delete_id = ?", [$delete_id], 'i');
                
                Security::logEvent('COUNCIL_RECOVER', "Recovered council member ID: {$deleted['council_id']}");
                header('Location: council.php?tab=active&success=recovered');
                exit();
            }
            $error = 'Failed to recover council member.';
            
        } elseif ($action === 'undo_edit') {
            $council_id = (int)$_POST['council_id'];
            $history_id = (int)$_POST['history_id'];
            
            // Get the edit history to revert
            $history = $db->getSingle(
                "SELECT * FROM council_edit_history WHERE history_id = ? AND council_id = ? AND is_reverted = 0",
                [$history_id, $council_id], 'ii'
            );
            
            if ($history) {
                // Parse the change
                $change = $history['new_value'];
                $parts = explode(': ', $change);
                $field = $parts[0];
                $values = explode(' → ', $parts[1] ?? '');
                
                // Mark as reverted
                $db->execute("UPDATE council_edit_history SET is_reverted = 1 WHERE history_id = ?", [$history_id], 'i');
                
                Security::logEvent('COUNCIL_UNDO_EDIT', "Undid edit for council member ID: $council_id");
                header('Location: council.php?tab=edited&success=undo_edit');
                exit();
            }
            $error = 'Failed to undo edit.';
        }
    }
}

$csrf_token = Security::generateCSRFToken();
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
        :root {
            --primary-color: #375a7f;
            --secondary-color: #2c4a6b;
            --success-color: #28a745;
            --warning-color: #ffc107;
            --danger-color: #dc3545;
            --info-color: #17a2b8;
        }

        * {
            box-sizing: border-box;
        }

        body {
            background: #f4f7fc;
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

        /* Sidebar Styles */
        #sidebar-wrapper {
            background: #375a7f;
            color: #fff;
            width: 250px;
            height: 100vh;
            overflow-y: auto;
            transition: width 0.3s ease;
            box-shadow: 4px 0 10px rgba(0,0,0,0.1);
            white-space: nowrap;
        }

        #sidebar-wrapper.collapsed {
            width: 70px;
        }

        #sidebar-wrapper.collapsed .sidebar-heading span {
            display: none;
        }

        #sidebar-wrapper.collapsed .list-group-item span {
            display: none;
        }

        #sidebar-wrapper.collapsed .list-group-item i {
            margin-right: 0;
            width: 100%;
            text-align: center;
            font-size: 1.2rem;
        }

        #sidebar-wrapper.collapsed .list-group-item {
            padding: 15px 0;
            text-align: center;
        }

        #sidebar-wrapper.collapsed .badge {
            display: none;
        }

        #sidebar-wrapper.collapsed .sidebar-heading img {
            display: none;
        }

        #sidebar-wrapper .sidebar-heading {
            padding: 1.2rem 1rem;
            font-size: 1.4rem;
            font-weight: 600;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            background: #375a7f;
            color: white;
            text-align: left;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        #sidebar-wrapper.collapsed .sidebar-heading {
            justify-content: center;
            padding: 1.2rem 0;
        }

        #sidebar-wrapper .sidebar-heading img {
            height: 30px;
            width: auto;
            margin-right: 10px;
            vertical-align: middle;
        }

        .menu-toggle {
            background: transparent;
            border: none;
            font-size: 1.5rem;
            color: white;
            cursor: pointer;
            padding: 0 10px;
            transition: all 0.2s;
        }

        .menu-toggle:hover {
            color: #fff;
            transform: scale(1.1);
        }

        .header-logo {
            height: 30px;
            width: auto;
            margin-right: 10px;
            vertical-align: middle;
            transition: all 0.3s ease;
            display: none;
        }

        #sidebar-wrapper.collapsed ~ #page-content-wrapper .header-logo {
            display: inline-block;
        }

        #sidebar-wrapper .list-group-item {
            background: transparent;
            border: none;
            color: rgba(255,255,255,0.9);
            padding: 0.8rem 1.2rem;
            font-weight: 500;
            transition: all 0.2s;
            font-size: 0.95rem;
            text-align: left;
        }

        #sidebar-wrapper.collapsed .list-group-item {
            padding: 15px 0;
            text-align: center;
        }

        #sidebar-wrapper .list-group-item:hover,
        #sidebar-wrapper .list-group-item.active {
            background: rgba(255,255,255,0.15);
            color: #fff;
            border-left: 4px solid #fff;
        }

        #sidebar-wrapper.collapsed .list-group-item:hover,
        #sidebar-wrapper.collapsed .list-group-item.active {
            border-left: none;
            border-bottom: 2px solid #fff;
        }

        #sidebar-wrapper .list-group-item i {
            width: 24px;
            text-align: center;
            margin-right: 10px;
            font-size: 1rem;
            transition: all 0.3s ease;
        }

        #sidebar-wrapper.collapsed .list-group-item i {
            margin-right: 0;
            width: 100%;
            font-size: 1.2rem;
        }

        #page-content-wrapper {
            flex: 1;
            background: #f4f7fc;
            height: 100vh;
            overflow-y: auto;
            padding: 0;
        }

        /* Navbar */
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

        .navbar-left {
            display: flex;
            align-items: center;
        }

        .navbar-brand {
            font-size: 1.2rem;
            font-weight: 500;
            color: #375a7f !important;
            display: flex;
            align-items: center;
        }

        .navbar-brand i {
            color: #375a7f;
        }

        .navbar-right {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        /* Container */
        .council-container {
            padding: 20px;
            max-width: 1400px;
            margin: 0 auto;
        }

        /* Tabs Navigation */
        .tabs-nav {
            display: flex;
            gap: 10px;
            margin-bottom: 25px;
            background: white;
            padding: 8px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            flex-wrap: wrap;
        }

        .tab-btn {
            padding: 10px 20px;
            background: transparent;
            border: none;
            border-radius: 10px;
            font-weight: 600;
            font-size: 0.9rem;
            color: #6c757d;
            transition: all 0.3s ease;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .tab-btn i {
            font-size: 1rem;
        }

        .tab-btn.active {
            background: linear-gradient(135deg, #375a7f, #2c4a6b);
            color: white;
            box-shadow: 0 4px 12px rgba(55,90,127,0.2);
        }

        .tab-btn:hover:not(.active) {
            background: #f8f9fa;
            color: #375a7f;
        }

        .tab-count {
            background: rgba(0,0,0,0.1);
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 500;
        }

        .tab-btn.active .tab-count {
            background: rgba(255,255,255,0.2);
        }

        /* Action Bar */
        .action-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 15px;
        }

        .search-box {
            display: flex;
            gap: 10px;
            flex: 1;
            max-width: 400px;
        }

        .search-box input {
            flex: 1;
            padding: 10px 15px;
            border: 1px solid #dee2e6;
            border-radius: 10px;
            font-size: 0.9rem;
            transition: all 0.3s;
        }

        .search-box input:focus {
            outline: none;
            border-color: #375a7f;
            box-shadow: 0 0 0 3px rgba(55,90,127,0.1);
        }

        .search-box button {
            padding: 10px 20px;
            background: #375a7f;
            color: white;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.3s;
        }

        .search-box button:hover {
            background: #2c4a6b;
            transform: translateY(-2px);
        }

        .btn-add {
            padding: 10px 24px;
            background: linear-gradient(135deg, #28a745, #20c997);
            color: white;
            border: none;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .btn-add:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(40,167,69,0.3);
        }

        /* Council Cards Grid */
        .council-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 20px;
        }

        .council-card {
            background: white;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            transition: all 0.3s ease;
            position: relative;
        }

        .council-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 24px rgba(0,0,0,0.12);
        }

        .edited-badge {
            position: absolute;
            top: 10px;
            right: 10px;
            background: #ffc107;
            color: #856404;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            z-index: 10;
        }

        .card-header {
            background: linear-gradient(135deg, #375a7f, #2c4a6b);
            padding: 20px;
            text-align: center;
            position: relative;
        }

        .member-photo {
            width: 90px;
            height: 90px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid white;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            background: #f8f9fa;
        }

        .member-photo-placeholder {
            width: 90px;
            height: 90px;
            border-radius: 50%;
            background: rgba(255,255,255,0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto;
            border: 3px solid white;
        }

        .member-photo-placeholder i {
            font-size: 2.5rem;
            color: white;
        }

        .member-name {
            margin-top: 12px;
            font-size: 1.1rem;
            font-weight: 700;
            color: white;
        }

        .member-position {
            font-size: 0.8rem;
            color: rgba(255,255,255,0.9);
            margin-top: 4px;
        }

        .card-body {
            padding: 20px;
        }

        .info-row {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 12px;
            font-size: 0.85rem;
            color: #4a5568;
        }

        .info-row i {
            width: 20px;
            color: #375a7f;
        }

        .info-row span {
            flex: 1;
        }

        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-active {
            background: #d4edda;
            color: #155724;
        }

        .status-inactive {
            background: #e2e3e5;
            color: #383d41;
        }

        .card-actions {
            display: flex;
            gap: 8px;
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid #e9ecef;
            flex-wrap: wrap;
        }

        .action-btn {
            flex: 1;
            padding: 8px;
            border: none;
            border-radius: 8px;
            font-size: 0.75rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }

        .action-btn-view {
            background: #e3f2fd;
            color: #0d47a1;
        }

        .action-btn-view:hover {
            background: #0d47a1;
            color: white;
        }

        .action-btn-edit {
            background: #fff3e0;
            color: #e67e22;
        }

        .action-btn-edit:hover {
            background: #e67e22;
            color: white;
        }

        .action-btn-delete {
            background: #fee9e7;
            color: #c0392b;
        }

        .action-btn-delete:hover {
            background: #c0392b;
            color: white;
        }

        .action-btn-recover {
            background: #d4edda;
            color: #28a745;
        }

        .action-btn-recover:hover {
            background: #28a745;
            color: white;
        }

        .action-btn-permanent {
            background: #f8d7da;
            color: #721c24;
        }

        .action-btn-permanent:hover {
            background: #721c24;
            color: white;
        }

        .action-btn-undo {
            background: #fff3cd;
            color: #856404;
        }

        .action-btn-undo:hover {
            background: #856404;
            color: white;
        }

        .edit-info {
            font-size: 0.7rem;
            color: #6c757d;
            margin-top: 8px;
            padding-top: 8px;
            border-top: 1px dashed #e9ecef;
        }

        /* Modal Styles */
        .modal-header-custom {
            background: linear-gradient(135deg, #375a7f, #2c4a6b);
            color: white;
            padding: 15px 20px;
            border: none;
        }

        .modal-header-custom .btn-close {
            filter: brightness(0) invert(1);
        }

        .logo-in-modal {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid #e9ecef;
        }

        .logo-in-modal img {
            width: 50px;
            height: 50px;
            object-fit: contain;
        }

        .logo-in-modal h5 {
            margin: 0;
            font-size: 1rem;
            color: #2c3e50;
        }

        .logo-in-modal p {
            margin: 0;
            font-size: 0.7rem;
            color: #6c757d;
        }

        .form-label {
            font-weight: 500;
            font-size: 0.8rem;
            color: #4a5568;
            margin-bottom: 5px;
        }

        .form-control, .form-select {
            border: 1px solid #dee2e6;
            border-radius: 10px;
            padding: 10px 12px;
            font-size: 0.9rem;
        }

        .form-control:focus, .form-select:focus {
            border-color: #375a7f;
            box-shadow: 0 0 0 3px rgba(55,90,127,0.1);
        }

        .photo-upload-area {
            text-align: center;
            margin-bottom: 20px;
        }

        .current-photo {
            position: relative;
            display: inline-block;
        }

        .current-photo img {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid #dee2e6;
        }

        .photo-upload-btn {
            position: absolute;
            bottom: 0;
            right: 0;
            background: #375a7f;
            border: none;
            border-radius: 50%;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            cursor: pointer;
            transition: all 0.3s;
        }

        .photo-upload-btn:hover {
            background: #2c4a6b;
            transform: scale(1.05);
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            background: white;
            border-radius: 16px;
        }

        .empty-icon {
            font-size: 4rem;
            color: #dee2e6;
            margin-bottom: 20px;
        }

        .history-list {
            max-height: 300px;
            overflow-y: auto;
        }

        .history-item {
            padding: 10px;
            border-bottom: 1px solid #e9ecef;
            font-size: 0.8rem;
        }

        @media (max-width: 768px) {
            .council-grid {
                grid-template-columns: 1fr;
            }
            
            .action-bar {
                flex-direction: column;
            }
            
            .search-box {
                max-width: 100%;
            }
            
            .tabs-nav {
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <div id="wrapper">
        <!-- Sidebar -->
        <div id="sidebar-wrapper">
            <div class="sidebar-heading">
                <img src="../assets/images/harana-logo.png" alt="Harana" onerror="this.src=''; this.onerror=null; this.innerHTML='Harana';">
                <span>Harana</span>
                <button class="menu-toggle" id="menuToggle">
                    <i class="fas fa-bars"></i>
                </button>
            </div>
            <div class="list-group list-group-flush mt-2">
                <a href="dashboard.php" class="list-group-item"><i class="fas fa-tachometer-alt"></i><span>Dashboard</span></a>
                <a href="members.php" class="list-group-item"><i class="fas fa-users"></i><span>Members</span></a>
                <a href="council.php" class="list-group-item active"><i class="fas fa-user-tie"></i><span>Council</span></a>
                <a href="payments.php" class="list-group-item"><i class="fas fa-credit-card"></i><span>Payments</span></a>
                <a href="reports.php" class="list-group-item"><i class="fas fa-chart-bar"></i><span>Reports</span></a>
                <?php if ($current_user['role'] === 'admin'): ?>
                <a href="pending_users.php" class="list-group-item position-relative">
                    <i class="fas fa-user-clock"></i><span>Pending</span>
                    <?php if ($pending_count > 0): ?>
                        <span class="badge bg-danger position-absolute top-0 start-100 translate-middle badge-count"><?php echo $pending_count; ?></span>
                    <?php endif; ?>
                </a>
                <?php endif; ?>
                <a href="settings.php" class="list-group-item"><i class="fas fa-cog"></i><span>Settings</span></a>
                <a href="../logout.php" class="list-group-item" onclick="return confirm('Are you sure?');"><i class="fas fa-sign-out-alt"></i><span>Logout</span></a>
            </div>
        </div>

        <!-- Main Content -->
        <div id="page-content-wrapper">
            <nav class="navbar navbar-light bg-light">
                <div class="navbar-left">
                    <img src="../assets/images/harana-logo.png" alt="Harana" class="header-logo" id="headerLogo" onerror="this.style.display='none';">
                    <span class="navbar-brand"><i class="fas fa-user-tie me-2"></i>Council Management</span>
                </div>
                <div class="navbar-right">
                    <span class="text-muted small">
                        <i class="fas fa-calendar-alt me-1"></i><?php echo date('F j, Y'); ?>
                    </span>
                    <span class="small"><i class="fas fa-user-circle me-2"></i><?php echo htmlspecialchars($current_user['full_name'] ?? $current_user['username']); ?></span>
                </div>
            </nav>

            <div class="council-container">
                <?php if ($message): ?>
                    <div class="alert alert-success alert-dismissible fade show"><?php echo $message; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
                <?php endif; ?>
                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show"><?php echo $error; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
                <?php endif; ?>

                <!-- Tabs Navigation -->
                <div class="tabs-nav">
                    <a href="?tab=active" class="tab-btn <?php echo $active_tab == 'active' ? 'active' : ''; ?>">
                        <i class="fas fa-user-check"></i> Active
                        <span class="tab-count"><?php echo $active_count; ?></span>
                    </a>
                    <a href="?tab=inactive" class="tab-btn <?php echo $active_tab == 'inactive' ? 'active' : ''; ?>">
                        <i class="fas fa-user-slash"></i> Inactive
                        <span class="tab-count"><?php echo $inactive_count; ?></span>
                    </a>
                    <a href="?tab=edited" class="tab-btn <?php echo $active_tab == 'edited' ? 'active' : ''; ?>">
                        <i class="fas fa-history"></i> Edited
                        <span class="tab-count"><?php echo $edited_count; ?></span>
                    </a>
                    <a href="?tab=deleted" class="tab-btn <?php echo $active_tab == 'deleted' ? 'active' : ''; ?>">
                        <i class="fas fa-trash-alt"></i> Deleted
                        <span class="tab-count"><?php echo $deleted_count; ?></span>
                    </a>
                </div>

                <!-- Action Bar -->
                <div class="action-bar">
                    <div class="search-box">
                        <input type="text" id="searchInput" placeholder="Search by name, position, email or contact..." onkeyup="filterCards()">
                        <button onclick="filterCards()"><i class="fas fa-search"></i> Search</button>
                    </div>
                    <?php if ($active_tab !== 'deleted'): ?>
                    <button class="btn-add" data-bs-toggle="modal" data-bs-target="#addCouncilModal">
                        <i class="fas fa-plus"></i> Add Council Member
                    </button>
                    <?php endif; ?>
                </div>

                <!-- Council Cards Grid -->
                <div class="council-grid" id="councilGrid">
                    <?php if (empty($council_members)): ?>
                        <div class="empty-state">
                            <div class="empty-icon">
                                <i class="fas fa-user-tie"></i>
                            </div>
                            <h5>No Council Members Found</h5>
                            <p class="text-muted">
                                <?php if ($active_tab === 'deleted'): ?>
                                    No deleted members in trash.
                                <?php elseif ($active_tab === 'edited'): ?>
                                    No edited members history.
                                <?php else: ?>
                                    Click "Add Council Member" to get started.
                                <?php endif; ?>
                            </p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($council_members as $c): ?>
                            <?php if ($active_tab === 'deleted'): 
                                $deleted_data = json_decode($c['original_data'], true);
                            ?>
                            <div class="council-card" data-name="<?php echo strtolower($deleted_data['full_name'] ?? ''); ?>" data-position="<?php echo strtolower($deleted_data['position'] ?? ''); ?>">
                                <div class="card-header">
                                    <?php if (!empty($deleted_data['photo'])): ?>
                                        <img src="../<?php echo htmlspecialchars($deleted_data['photo']); ?>" alt="<?php echo htmlspecialchars($deleted_data['full_name']); ?>" class="member-photo" onerror="this.src='../assets/images/default-avatar.png'">
                                    <?php else: ?>
                                        <div class="member-photo-placeholder">
                                            <i class="fas fa-user-circle"></i>
                                        </div>
                                    <?php endif; ?>
                                    <div class="member-name"><?php echo htmlspecialchars($deleted_data['full_name'] ?? 'Unknown'); ?></div>
                                    <div class="member-position"><?php echo htmlspecialchars($deleted_data['position'] ?? 'No Position'); ?></div>
                                </div>
                                <div class="card-body">
                                    <div class="info-row">
                                        <i class="fas fa-phone-alt"></i>
                                        <span><?php echo htmlspecialchars($deleted_data['contact_number'] ?: 'Not provided'); ?></span>
                                    </div>
                                    <div class="info-row">
                                        <i class="fas fa-envelope"></i>
                                        <span><?php echo htmlspecialchars($deleted_data['email'] ?: 'Not provided'); ?></span>
                                    </div>
                                    <div class="info-row">
                                        <i class="fas fa-trash"></i>
                                        <span>Deleted: <?php echo date('M d, Y', strtotime($c['deleted_at'])); ?></span>
                                    </div>
                                    <div class="card-actions">
                                        <button class="action-btn action-btn-recover" onclick="showRecoverModal(<?php echo $c['delete_id']; ?>, '<?php echo addslashes($deleted_data['full_name']); ?>')">
                                            <i class="fas fa-trash-restore"></i> Recover
                                        </button>
                                        <button class="action-btn action-btn-permanent" onclick="showPermanentDeleteModal(<?php echo $c['delete_id']; ?>, '<?php echo addslashes($deleted_data['full_name']); ?>')">
                                            <i class="fas fa-trash-alt"></i> Permanent Delete
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <?php else: ?>
                            <div class="council-card" data-name="<?php echo strtolower($c['full_name']); ?>" data-position="<?php echo strtolower($c['position']); ?>" data-email="<?php echo strtolower($c['email']); ?>" data-contact="<?php echo strtolower($c['contact_number']); ?>">
                                <?php if ($active_tab === 'edited' && isset($c['edit_count']) && $c['edit_count'] > 0): ?>
                                    <div class="edited-badge">
                                        <i class="fas fa-edit"></i> Edited
                                    </div>
                                <?php endif; ?>
                                <div class="card-header">
                                    <?php if (!empty($c['photo'])): ?>
                                        <img src="../<?php echo htmlspecialchars($c['photo']); ?>" alt="<?php echo htmlspecialchars($c['full_name']); ?>" class="member-photo" onerror="this.src='../assets/images/default-avatar.png'">
                                    <?php else: ?>
                                        <div class="member-photo-placeholder">
                                            <i class="fas fa-user-circle"></i>
                                        </div>
                                    <?php endif; ?>
                                    <div class="member-name"><?php echo htmlspecialchars($c['full_name']); ?></div>
                                    <div class="member-position"><?php echo htmlspecialchars($c['position']); ?></div>
                                </div>
                                <div class="card-body">
                                    <div class="info-row">
                                        <i class="fas fa-phone-alt"></i>
                                        <span><?php echo htmlspecialchars($c['contact_number'] ?: 'Not provided'); ?></span>
                                    </div>
                                    <div class="info-row">
                                        <i class="fas fa-envelope"></i>
                                        <span><?php echo htmlspecialchars($c['email'] ?: 'Not provided'); ?></span>
                                    </div>
                                    <div class="info-row">
                                        <i class="fas fa-calendar-alt"></i>
                                        <span>Term: <?php echo $c['term_start'] ? date('M Y', strtotime($c['term_start'])) : '—'; ?> - <?php echo $c['term_end'] ? date('M Y', strtotime($c['term_end'])) : '—'; ?></span>
                                    </div>
                                    <div class="info-row">
                                        <i class="fas fa-flag-checkered"></i>
                                        <span><span class="status-badge <?php echo $c['status'] == 'active' ? 'status-active' : 'status-inactive'; ?>"><?php echo ucfirst($c['status']); ?></span></span>
                                    </div>
                                    <?php if ($active_tab === 'edited' && isset($c['last_edited_at'])): ?>
                                    <div class="edit-info">
                                        <i class="fas fa-clock me-1"></i> Last edited: <?php echo date('M d, Y H:i', strtotime($c['last_edited_at'])); ?>
                                    </div>
                                    <?php endif; ?>
                                    <div class="card-actions">
                                        <button class="action-btn action-btn-view" onclick="viewMemberDetails(<?php echo $c['council_id']; ?>)">
                                            <i class="fas fa-eye"></i> View
                                        </button>
                                        <button class="action-btn action-btn-edit" onclick="editCouncil(<?php echo $c['council_id']; ?>, '<?php echo addslashes($c['last_name']); ?>', '<?php echo addslashes($c['first_name']); ?>', '<?php echo addslashes($c['middle_name']); ?>', '<?php echo addslashes($c['full_name']); ?>', '<?php echo addslashes($c['position']); ?>', '<?php echo addslashes($c['contact_number']); ?>', '<?php echo addslashes($c['email']); ?>', '<?php echo $c['term_start']; ?>', '<?php echo $c['term_end']; ?>', '<?php echo $c['status']; ?>', '<?php echo addslashes($c['photo']); ?>')">
                                            <i class="fas fa-edit"></i> Edit
                                        </button>
                                        <?php if ($c['status'] == 'active'): ?>
                                        <button class="action-btn action-btn-delete" onclick="showDeleteModal(<?php echo $c['council_id']; ?>, '<?php echo addslashes($c['full_name']); ?>')">
                                            <i class="fas fa-trash-alt"></i> Delete
                                        </button>
                                        <?php endif; ?>
                                        <?php if ($active_tab === 'edited'): ?>
                                        <button class="action-btn action-btn-undo" onclick="showUndoEditModal(<?php echo $c['council_id']; ?>, '<?php echo addslashes($c['full_name']); ?>')">
                                            <i class="fas fa-undo-alt"></i> Undo
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Council Modal -->
    <div class="modal fade" id="addCouncilModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" id="addCouncilForm">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="action" value="add">
                    <div class="modal-header-custom">
                        <h5 class="modal-title"><i class="fas fa-user-plus me-2"></i>Add Council Member</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="logo-in-modal">
                            <img src="../assets/images/harana-logo.png" alt="Harana Logo" onerror="this.style.display='none'">
                            <div>
                                <h5>Nagkaisang Haranista sa Gintong Luzon Phils, Inc.</h5>
                                <p>Council Member Registration Form</p>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Last Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="last_name" id="add_last_name" required>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">First Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="first_name" id="add_first_name" required>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Middle Name</label>
                                <input type="text" class="form-control" name="middle_name" id="add_middle_name">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Full Name (auto-generated)</label>
                            <input type="text" class="form-control" id="add_full_name_display" readonly>
                            <input type="hidden" name="full_name" id="add_full_name">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Position <span class="text-danger">*</span></label>
                            <select class="form-select" name="position" required>
                                <option value="">Select Position</option>
                                <?php foreach ($positions as $value => $label): ?>
                                    <option value="<?php echo htmlspecialchars($value); ?>"><?php echo htmlspecialchars($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Contact Number</label>
                            <input type="text" class="form-control" name="contact_number" placeholder="e.g., 09123456789">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Email Address</label>
                            <input type="email" class="form-control" name="email" placeholder="example@email.com">
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Term Start</label>
                                <input type="date" class="form-control" name="term_start">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Term End</label>
                                <input type="date" class="form-control" name="term_end">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success">Add Member</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Council Modal -->
    <div class="modal fade" id="editCouncilModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" id="editCouncilForm">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="council_id" id="edit_council_id">
                    <div class="modal-header-custom">
                        <h5 class="modal-title"><i class="fas fa-user-edit me-2"></i>Edit Council Member</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="logo-in-modal">
                            <img src="../assets/images/harana-logo.png" alt="Harana Logo" onerror="this.style.display='none'">
                            <div>
                                <h5>Nagkaisang Haranista sa Gintong Luzon Phils, Inc.</h5>
                                <p>Update Council Member Information</p>
                            </div>
                        </div>

                        <div class="photo-upload-area">
                            <div class="current-photo">
                                <img id="edit_photo_preview" src="../assets/images/default-avatar.png" alt="Profile Photo">
                                <button type="button" class="photo-upload-btn" onclick="document.getElementById('photo_file_input').click();" title="Upload Photo">
                                    <i class="fas fa-camera"></i>
                                </button>
                            </div>
                            <input type="file" id="photo_file_input" accept="image/*" style="display: none;">
                            <div id="photo_upload_progress" style="display: none; margin-top: 10px;">
                                <div class="progress" style="height: 5px;">
                                    <div class="progress-bar progress-bar-striped progress-bar-animated" style="width: 0%"></div>
                                </div>
                            </div>
                            <div id="photo_actions" style="display: none; margin-top: 8px;">
                                <button type="button" class="btn btn-sm btn-outline-danger" onclick="deletePhoto()">
                                    <i class="fas fa-trash me-1"></i> Remove Photo
                                </button>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Last Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="last_name" id="edit_last_name" required>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">First Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="first_name" id="edit_first_name" required>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Middle Name</label>
                                <input type="text" class="form-control" name="middle_name" id="edit_middle_name">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Full Name (auto-generated)</label>
                            <input type="text" class="form-control" id="edit_full_name_display" readonly>
                            <input type="hidden" name="full_name" id="edit_full_name">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Position <span class="text-danger">*</span></label>
                            <select class="form-select" name="position" id="edit_position" required>
                                <option value="">Select Position</option>
                                <?php foreach ($positions as $value => $label): ?>
                                    <option value="<?php echo htmlspecialchars($value); ?>"><?php echo htmlspecialchars($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Contact Number</label>
                            <input type="text" class="form-control" name="contact_number" id="edit_contact">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Email Address</label>
                            <input type="email" class="form-control" name="email" id="edit_email">
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Term Start</label>
                                <input type="date" class="form-control" name="term_start" id="edit_term_start">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Term End</label>
                                <input type="date" class="form-control" name="term_end" id="edit_term_end">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Status</label>
                            <select class="form-select" name="status" id="edit_status">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Member</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- View Council Member Modal -->
    <div class="modal fade" id="viewCouncilModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header-custom">
                    <h5 class="modal-title"><i class="fas fa-file-alt me-2"></i>Council Member Details</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="viewModalBody">
                    <div class="text-center p-5" id="viewLoading">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                    </div>
                    <div id="viewContent" style="display: none;"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary" id="viewEditBtn" style="display: none;"><i class="fas fa-edit me-2"></i>Edit</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Delete Modal -->
    <div class="modal fade" id="deleteCouncilModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="council_id" id="delete_council_id">
                    <div class="modal-header-custom" style="background: linear-gradient(135deg, #dc3545, #c82333);">
                        <h5 class="modal-title"><i class="fas fa-trash-alt me-2"></i>Confirm Delete</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body text-center">
                        <i class="fas fa-trash-alt fa-4x text-danger mb-3"></i>
                        <p>Are you sure you want to delete <strong id="delete_council_name"></strong>?</p>
                        <p class="text-muted small">This member will be moved to the Deleted tab and can be recovered later.</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">Delete</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Recover Modal -->
    <div class="modal fade" id="recoverCouncilModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="action" value="recover">
                    <input type="hidden" name="delete_id" id="recover_delete_id">
                    <div class="modal-header-custom" style="background: linear-gradient(135deg, #28a745, #20c997);">
                        <h5 class="modal-title"><i class="fas fa-trash-restore me-2"></i>Confirm Recovery</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body text-center">
                        <i class="fas fa-trash-restore fa-4x text-success mb-3"></i>
                        <p>Are you sure you want to recover <strong id="recover_council_name"></strong>?</p>
                        <p class="text-muted small">This member will be restored to the Active tab.</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success">Recover</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Permanent Delete Modal -->
    <div class="modal fade" id="permanentDeleteModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="action" value="permanent_delete">
                    <input type="hidden" name="delete_id" id="permanent_delete_id">
                    <div class="modal-header-custom" style="background: linear-gradient(135deg, #721c24, #c0392b);">
                        <h5 class="modal-title"><i class="fas fa-skull-crossbones me-2"></i>Permanent Delete</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body text-center">
                        <i class="fas fa-skull-crossbones fa-4x text-danger mb-3"></i>
                        <p>Are you sure you want to <strong class="text-danger">PERMANENTLY DELETE</strong> <strong id="permanent_delete_name"></strong>?</p>
                        <p class="text-danger">This action CANNOT be undone. All data will be removed permanently.</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">Permanently Delete</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Undo Edit Modal -->
    <div class="modal fade" id="undoEditModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="action" value="undo_edit">
                    <input type="hidden" name="council_id" id="undo_council_id">
                    <input type="hidden" name="history_id" id="undo_history_id">
                    <div class="modal-header-custom" style="background: linear-gradient(135deg, #ffc107, #e0a800);">
                        <h5 class="modal-title"><i class="fas fa-undo-alt me-2"></i>Undo Edit</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body text-center">
                        <i class="fas fa-undo-alt fa-4x text-warning mb-3"></i>
                        <p>Are you sure you want to undo the last edit for <strong id="undo_council_name"></strong>?</p>
                        <p class="text-muted small">This will revert the member to the previous version.</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-warning">Undo Edit</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        let currentViewId = null;
        let currentEditId = null;

        // Auto-generate full name
        function updateFullName(prefix) {
            let lastName = $(`#${prefix}_last_name`).val().trim();
            let firstName = $(`#${prefix}_first_name`).val().trim();
            let middleName = $(`#${prefix}_middle_name`).val().trim();
            
            let fullName = '';
            if (lastName) fullName += lastName;
            if (firstName) fullName += (fullName ? ', ' : '') + firstName;
            if (middleName) fullName += ' ' + middleName;
            
            $(`#${prefix}_full_name_display`).val(fullName);
            $(`#${prefix}_full_name`).val(fullName);
        }

        $('#add_last_name, #add_first_name, #add_middle_name').on('keyup change', function() {
            updateFullName('add');
        });

        $('#edit_last_name, #edit_first_name, #edit_middle_name').on('keyup change', function() {
            updateFullName('edit');
        });

        // Edit council function
        function editCouncil(id, lastName, firstName, middleName, fullName, position, contact, email, termStart, termEnd, status, photo) {
            $('#edit_council_id').val(id);
            $('#edit_last_name').val(lastName || '');
            $('#edit_first_name').val(firstName || '');
            $('#edit_middle_name').val(middleName || '');
            $('#edit_full_name_display').val(fullName || '');
            $('#edit_full_name').val(fullName || '');
            $('#edit_position').val(position);
            $('#edit_contact').val(contact || '');
            $('#edit_email').val(email || '');
            $('#edit_term_start').val(termStart || '');
            $('#edit_term_end').val(termEnd || '');
            $('#edit_status').val(status || 'active');
            
            if (photo) {
                $('#edit_photo_preview').attr('src', '../' + photo);
                $('#photo_actions').show();
            } else {
                $('#edit_photo_preview').attr('src', '../assets/images/default-avatar.png');
                $('#photo_actions').hide();
            }
            
            $('#editCouncilModal').modal('show');
        }

        // View member details
        function viewMemberDetails(id) {
            currentViewId = id;
            $('#viewLoading').show();
            $('#viewContent').hide();
            $('#viewEditBtn').hide();
            
            $.ajax({
                url: 'get_council_member.php',
                type: 'GET',
                data: { id: id, t: new Date().getTime() },
                dataType: 'json',
                success: function(response) {
                    $('#viewLoading').hide();
                    if (response && response.success && response.data) {
                        displayMemberDetails(response.data);
                        $('#viewEditBtn').show();
                    } else {
                        $('#viewContent').html('<div class="alert alert-danger">Failed to load member details.</div>').show();
                    }
                },
                error: function() {
                    $('#viewLoading').hide();
                    $('#viewContent').html('<div class="alert alert-danger">Error loading member details.</div>').show();
                }
            });
        }

        // Display member details
        function displayMemberDetails(member) {
            const photoUrl = member.photo ? '../' + member.photo : '../assets/images/default-avatar.png';
            const fullName = member.full_name || member.first_name + ' ' + member.last_name;
            
            const termStart = member.term_start ? new Date(member.term_start).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' }) : 'Not set';
            const termEnd = member.term_end ? new Date(member.term_end).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' }) : 'Not set';
            
            const html = `
                <div class="text-center mb-4">
                    <img src="${photoUrl}" alt="${fullName}" class="rounded-circle" style="width: 120px; height: 120px; object-fit: cover; border: 3px solid #dee2e6;">
                    <h3 class="mt-3">${fullName}</h3>
                    <span class="badge ${member.status === 'active' ? 'bg-success' : 'bg-secondary'}">${member.status ? member.status.toUpperCase() : 'UNKNOWN'}</span>
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="card mb-3">
                            <div class="card-header bg-light">
                                <strong><i class="fas fa-user me-2"></i>Personal Information</strong>
                            </div>
                            <div class="card-body">
                                <table class="table table-sm table-borderless">
                                    <tr><td width="40%"><strong>Last Name:</strong></td><td>${member.last_name || '—'}</td></tr>
                                    <tr><td><strong>First Name:</strong></td><td>${member.first_name || '—'}</td></tr>
                                    <tr><td><strong>Middle Name:</strong></td><td>${member.middle_name || '—'}</td></tr>
                                    <tr><td><strong>Position:</strong></td><td>${member.position || '—'}</td></tr>
                                </table>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card mb-3">
                            <div class="card-header bg-light">
                                <strong><i class="fas fa-address-card me-2"></i>Contact Information</strong>
                            </div>
                            <div class="card-body">
                                <table class="table table-sm table-borderless">
                                    <tr><td width="40%"><strong>Contact Number:</strong></td><td>${member.contact_number || '—'}</td></tr>
                                    <tr><td><strong>Email:</strong></td><td>${member.email || '—'}</td></tr>
                                </table>
                            </div>
                        </div>
                        <div class="card">
                            <div class="card-header bg-light">
                                <strong><i class="fas fa-calendar-alt me-2"></i>Term Information</strong>
                            </div>
                            <div class="card-body">
                                <table class="table table-sm table-borderless">
                                    <tr><td width="40%"><strong>Term Start:</strong></td><td>${termStart}</td></tr>
                                    <tr><td><strong>Term End:</strong></td><td>${termEnd}</td></tr>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            
            $('#viewContent').html(html).show();
        }

        // View edit button handler
        $('#viewEditBtn').on('click', function() {
            if (currentViewId) {
                $('#viewCouncilModal').modal('hide');
                setTimeout(function() {
                    let editBtn = $(`.action-btn-edit[onclick*="${currentViewId}"]`);
                    if (editBtn.length) {
                        editBtn.click();
                    }
                }, 500);
            }
        });

        // Delete modal
        function showDeleteModal(id, name) {
            $('#delete_council_id').val(id);
            $('#delete_council_name').text(name);
            $('#deleteCouncilModal').modal('show');
        }

        // Recover modal
        function showRecoverModal(deleteId, name) {
            $('#recover_delete_id').val(deleteId);
            $('#recover_council_name').text(name);
            $('#recoverCouncilModal').modal('show');
        }

        // Permanent delete modal
        function showPermanentDeleteModal(deleteId, name) {
            $('#permanent_delete_id').val(deleteId);
            $('#permanent_delete_name').text(name);
            $('#permanentDeleteModal').modal('show');
        }

        // Undo edit modal
        function showUndoEditModal(councilId, name) {
            $('#undo_council_id').val(councilId);
            $('#undo_council_name').text(name);
            $('#undoEditModal').modal('show');
        }

        // Photo upload handler
        $('#photo_file_input').on('change', function() {
            let file = this.files[0];
            if (!file) return;
            
            let councilId = $('#edit_council_id').val();
            let formData = new FormData();
            formData.append('csrf_token', '<?php echo $csrf_token; ?>');
            formData.append('council_id', councilId);
            formData.append('photo', file);
            
            let progress = $('#photo_upload_progress').show().find('.progress-bar');
            progress.css('width', '0%');
            
            $.ajax({
                url: 'upload_council_photo.php',
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                xhr: function() {
                    let xhr = new XMLHttpRequest();
                    xhr.upload.addEventListener('progress', function(e) {
                        if (e.lengthComputable) {
                            let percent = (e.loaded / e.total) * 100;
                            progress.css('width', percent + '%');
                        }
                    });
                    return xhr;
                },
                success: function(response) {
                    setTimeout(function() {
                        $('#photo_upload_progress').hide();
                        if (response.success) {
                            $('#edit_photo_preview').attr('src', response.photo_url + '?t=' + new Date().getTime());
                            $('#photo_actions').show();
                            showAlert('success', response.message);
                        } else {
                            alert(response.message || 'Upload failed');
                        }
                    }, 500);
                },
                error: function() {
                    $('#photo_upload_progress').hide();
                    alert('Upload failed');
                }
            });
        });

        // Delete photo
        function deletePhoto() {
            if (!confirm('Are you sure you want to delete this profile picture?')) return;
            
            let councilId = $('#edit_council_id').val();
            $.ajax({
                url: 'delete_council_photo.php',
                type: 'POST',
                data: {
                    council_id: councilId,
                    csrf_token: '<?php echo $csrf_token; ?>'
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        $('#edit_photo_preview').attr('src', '../assets/images/default-avatar.png');
                        $('#photo_actions').hide();
                        showAlert('success', response.message);
                    } else {
                        alert(response.message || 'Delete failed');
                    }
                },
                error: function() {
                    alert('Delete failed');
                }
            });
        }

        // Show alert
        function showAlert(type, message) {
            let alertHtml = `<div class="alert alert-${type} alert-dismissible fade show">${message}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>`;
            $('.council-container').prepend(alertHtml);
            setTimeout(function() {
                $('.alert').fadeOut('slow', function() { $(this).remove(); });
            }, 3000);
        }

        // Search filter
        function filterCards() {
            let searchTerm = $('#searchInput').val().toLowerCase();
            $('.council-card').each(function() {
                let name = $(this).data('name') || '';
                let position = $(this).data('position') || '';
                let email = $(this).data('email') || '';
                let contact = $(this).data('contact') || '';
                
                if (name.includes(searchTerm) || position.includes(searchTerm) || email.includes(searchTerm) || contact.includes(searchTerm)) {
                    $(this).show();
                } else {
                    $(this).hide();
                }
            });
        }

        // Sidebar toggle
        const menuToggle = document.getElementById('menuToggle');
        const sidebar = document.getElementById('sidebar-wrapper');
        const headerLogo = document.getElementById('headerLogo');
        const sidebarCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';
        
        if (sidebarCollapsed) sidebar.classList.add('collapsed');
        if (menuToggle) {
            menuToggle.addEventListener('click', function() {
                sidebar.classList.toggle('collapsed');
                localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
            });
        }

        // Logo fallback
        const sidebarLogo = document.querySelector('.sidebar-heading img');
        if (sidebarLogo) {
            sidebarLogo.onerror = function() {
                this.style.display = 'none';
                this.nextSibling.textContent = 'Harana';
            };
        }
        if (headerLogo) {
            headerLogo.onerror = function() {
                this.style.display = 'none';
            };
        }
    </script>
</body>
</html>