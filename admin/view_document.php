<?php
// admin/view_document.php
// Secure admin-only passthrough for viewing member document uploads
// (medical certificate / birth certificate)
//
// Usage:
//   admin/view_document.php?member_code=20265525&doc_type=medical_certificate_file
//   admin/view_document.php?member_code=20265525&doc_type=birth_certificate_file

require_once '../includes/config.php';
require_once '../includes/db_connection.php';
require_once '../includes/security.php';
require_once '../includes/auth.php';

// ------------------------------------------------------------
// 1. Auth + role check (ADMIN ONLY)
// ------------------------------------------------------------
$auth->requireLogin();
$current_user = $auth->getCurrentUser();

if (($current_user['role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('Access denied. This action is restricted to administrators.');
}

// ------------------------------------------------------------
// 2. Input validation
// ------------------------------------------------------------
$member_code = isset($_GET['member_code']) ? trim(Security::sanitize($_GET['member_code'])) : '';
$doc_type    = isset($_GET['doc_type'])    ? trim($_GET['doc_type'])                          : '';

$allowed_doc_types = ['medical_certificate_file', 'birth_certificate_file'];

if ($member_code === '' || !in_array($doc_type, $allowed_doc_types, true)) {
    http_response_code(400);
    exit('Invalid request. Missing or invalid member_code / doc_type.');
}

// ------------------------------------------------------------
// 3. Look up the file path in the members table
// ------------------------------------------------------------
$db = Database::getInstance();

$row = $db->getSingle(
    "SELECT medical_certificate_file, birth_certificate_file
     FROM members
     WHERE member_code = ?",
    [$member_code],
    's'
);

if (!$row) {
    http_response_code(404);
    exit('Member not found.');
}

$relative_path = (string)($row[$doc_type] ?? '');

if ($relative_path === '' || $relative_path === null) {
    http_response_code(404);
    exit('No file was uploaded for this document.');
}

// ------------------------------------------------------------
// 4. Normalize path + prevent directory traversal
// ------------------------------------------------------------
$relative_path = ltrim(str_replace('\\', '/', $relative_path), '/');

// Reject any traversal attempt outright
if (strpos($relative_path, '..') !== false || strpos($relative_path, "\0") !== false) {
    http_response_code(400);
    exit('Invalid file path.');
}

// ------------------------------------------------------------
// 5. Resolve the absolute path (project root = parent of /admin)
// ------------------------------------------------------------
$project_root = realpath(__DIR__ . '/..');
if ($project_root === false) {
    http_response_code(500);
    exit('Server path error.');
}

$uploads_root = realpath($project_root . '/uploads');

/**
 * Helper: safely resolve a candidate path & confirm it lives inside /uploads
 */
$resolve = function (string $candidate) use ($project_root, $uploads_root) {
    $abs = realpath($project_root . '/' . ltrim($candidate, '/'));
    if ($abs === false || $uploads_root === false) {
        return false;
    }
    // Must exist, be a file, and be INSIDE the uploads folder
    if (!is_file($abs)) {
        return false;
    }
    if (strpos($abs, $uploads_root) !== 0) {
        return false;
    }
    return $abs;
};

$absolute_path = false;

// 5a. Try the path exactly as stored
$absolute_path = $resolve($relative_path);

// 5b. Fallback: if stored path is a bare filename, probe common folders
if ($absolute_path === false && strpos($relative_path, '/') === false) {
    $candidates = [
        'uploads/'          . $relative_path,
        'uploads/documents/'. $relative_path,
        'uploads/temp/'     . $relative_path,
        'uploads/members/'  . $relative_path,
        'uploads/certificates/' . $relative_path,
    ];
    foreach ($candidates as $cand) {
        $abs = $resolve($cand);
        if ($abs !== false) {
            $absolute_path = $abs;
            break;
        }
    }
}

// 5c. Last resort: if stored path starts with something other than "uploads/",
//     also try treating it as relative to uploads root.
if ($absolute_path === false && strpos($relative_path, 'uploads/') !== 0) {
    $absolute_path = $resolve('uploads/' . $relative_path);
}

if ($absolute_path === false) {
    http_response_code(404);
    exit('File not found on server.');
}

// ------------------------------------------------------------
// 6. MIME detection + whitelist
// ------------------------------------------------------------
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime  = $finfo->file($absolute_path) ?: 'application/octet-stream';

$allowed_mimes = [
    'image/jpeg',
    'image/png',
    'image/gif',
    'image/webp',
    'application/pdf',
];

if (!in_array($mime, $allowed_mimes, true)) {
    // Force download as octet-stream rather than risk inline execution
    $mime = 'application/octet-stream';
}

// ------------------------------------------------------------
// 7. Serve inline
// ------------------------------------------------------------
// Clean any buffered output so binary data isn't corrupted
while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($absolute_path));
header('Content-Disposition: inline; filename="' . basename($absolute_path) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=0, no-cache, no-store, must-revalidate');
header('Pragma: no-cache');

readfile($absolute_path);
exit;