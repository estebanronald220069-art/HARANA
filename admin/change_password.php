<?php
// admin/change_password.php
// ------------------------------------------------------------
// This page has been CONSOLIDATED into Settings → Account tab.
// Password change functionality now lives at:
//   settings.php?tab=account#change-password
//
// This file is kept only for backwards compatibility so that
// old bookmarks, emails, or hardcoded links don't break.
// It simply redirects the user to the new location.
// ------------------------------------------------------------

require_once '../includes/config.php';
require_once '../includes/db_connection.php';
require_once '../includes/security.php';
require_once '../includes/auth.php';

$auth->requireLogin();
$current_user = $auth->getCurrentUser();

// If somehow the session is invalid, log out
if (!$current_user) {
    $auth->logout();
    exit();
}

// Redirect to the Account tab, anchored to the change-password card
header('Location: settings.php?tab=account#change-password');
exit();