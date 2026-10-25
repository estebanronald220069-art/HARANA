<?php
// register.php
require_once 'includes/config.php';
require_once 'includes/db_connection.php';
require_once 'includes/security.php';
require_once 'includes/auth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// === Require a logged-in "joined" user (Step 1 must be done first) ===
$auth->requireLogin();
$current_user = $auth->getCurrentUser();

$error = '';
$success = '';
$form_data = [];

$db = Database::getInstance();

// Fetch fresh user record
$user_info = $db->getSingle(
    "SELECT * FROM users WHERE user_id = ?",
    [$current_user['user_id']], 'i'
);
if (!$user_info) { header('Location: logout.php'); exit; }

// Already approved? Go to dashboard.
if (($user_info['account_status'] ?? '') === 'approved') {
    header('Location: user/dashboard.php');
    exit();
}

// Existing application from previous submission or rejection
$existing_app = null;
try {
    $existing_app = $db->getSingle(
        "SELECT * FROM pending_users WHERE user_id = ? ORDER BY id DESC LIMIT 1",
        [$current_user['user_id']], 'i'
    );
} catch (Throwable $e) { $existing_app = null; }

// Rejection note
$rejection_note = null;
if ($existing_app && $existing_app['status'] === 'rejected') {
    try {
        $rejection_note = $db->getSingle(
            "SELECT * FROM pending_user_rejections WHERE pending_user_id = ? ORDER BY id DESC LIMIT 1",
            [$existing_app['id']], 'i'
        );
    } catch (Throwable $e) { $rejection_note = null; }
}

$existing_account = true;
$prefill_email    = $user_info['email'];
$prefill_username = $user_info['username'];

// Prefill the wizard from previous submission
if ($existing_app && in_array($existing_app['status'], ['pending','rejected'], true)) {
    $form_data = $existing_app;
}

$chapters = $db->getAll(
    "SELECT chapter_id, chapter_name
     FROM chapters
     WHERE is_active = 1
     ORDER BY sort_order ASC, chapter_name ASC"
);

$groupsByChapter = [];
$allGroups = $db->getAll(
    "SELECT group_id, group_name, chapter_id
     FROM groups
     WHERE is_active = 1
     ORDER BY group_name ASC"
);
foreach ($allGroups as $g) {
    $groupsByChapter[$g['chapter_id']][] = [
        'group_id'   => $g['group_id'],
        'group_name' => $g['group_name'],
    ];
}

// City data for JavaScript
$cities_data = [
    'Occidental Mindoro' => ['San Jose', 'Mamburao', 'Sablayan', 'Calintaan', 'Rizal', 'Abra de Ilog', 'Paluan', 'Santa Cruz'],
    'Nueva Ecija' => ['Palayan City', 'Cabanatuan', 'Gapan', 'San Jose', 'Science City of Muñoz', 'Guimba', 'Talavera', 'San Leonardo', 'Santa Rosa', 'General Tinio'],
    'Pampanga' => ['San Fernando', 'Angeles City', 'Mabalacat', 'Mexico', 'Arayat', 'Candaba', 'Lubao', 'Porac', 'Floridablanca', 'Guagua'],
    'Bulacan' => ['Malolos', 'Meycauayan', 'San Jose del Monte', 'Baliuag', 'Marilao', 'Bocauе', 'Santa Maria', 'Pulilan', 'Plaridel', 'Norzagaray'],
    'Tarlac' => ['Tarlac City', 'Concepcion', 'Capas', 'Paniqui', 'Gerona', 'Camiling', 'Moncada', 'Victoria', 'San Jose', 'La Paz']
];

/* ============================================================
   FILE UPLOAD HELPER
   ============================================================ */
function handleUpload(string $fieldName, string $subfolder = 'pending'): array
{
    // No file provided — that's OK, both certs are optional at registration
    if (!isset($_FILES[$fieldName]) || $_FILES[$fieldName]['error'] === UPLOAD_ERR_NO_FILE) {
        return ['status' => 'skipped', 'path' => null, 'message' => 'No file uploaded'];
    }

    $file = $_FILES[$fieldName];

    // Upload error?
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errMsg = match ($file['error']) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File is too large',
            UPLOAD_ERR_PARTIAL                        => 'File upload was interrupted',
            UPLOAD_ERR_NO_TMP_DIR                     => 'Server temp folder missing',
            UPLOAD_ERR_CANT_WRITE                     => 'Server cannot write file',
            UPLOAD_ERR_EXTENSION                      => 'Upload blocked by server',
            default                                   => 'Upload error code ' . $file['error'],
        };
        return ['status' => 'error', 'path' => null, 'message' => $errMsg];
    }

    // Size check: 10MB max
    if ($file['size'] > 10 * 1024 * 1024) {
        return ['status' => 'error', 'path' => null, 'message' => 'File exceeds 10MB limit'];
    }

    // Type check — whitelist by extension + MIME
    $allowedMime = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
        'application/pdf' => 'pdf',
    ];

    $finfo    = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!isset($allowedMime[$mimeType])) {
        return ['status' => 'error', 'path' => null, 'message' => 'Invalid file type: ' . $mimeType];
    }

    $ext = $allowedMime[$mimeType];

    // Prepare destination
    $projectRoot  = __DIR__;
    $uploadsDir   = $projectRoot . '/uploads/' . $subfolder;

    if (!is_dir($uploadsDir)) {
        if (!@mkdir($uploadsDir, 0755, true) && !is_dir($uploadsDir)) {
            return ['status' => 'error', 'path' => null, 'message' => 'Cannot create upload folder'];
        }
    }

    // Unique filename
    $prefix    = match ($fieldName) {
        'registration_photo'       => 'reg',
        'medical_certificate_file' => 'med',
        'birth_certificate_file'   => 'birth',
        default                    => 'file',
    };
    $uniqueName = $prefix . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $destPath   = $uploadsDir . '/' . $uniqueName;

    // Move from temp
    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        return ['status' => 'error', 'path' => null, 'message' => 'Failed to move uploaded file'];
    }

    // Relative path used in DB and in <img src>
    $relPath = 'uploads/' . $subfolder . '/' . $uniqueName;

    return ['status' => 'ok', 'path' => $relPath, 'message' => 'Uploaded'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token';
    } else {
        $data = [];

        // PERSONAL INFORMATION
        $data['first_name']  = Security::sanitize($_POST['first_name'] ?? '');
        $data['last_name']   = Security::sanitize($_POST['last_name'] ?? '');
        $data['middle_name'] = Security::sanitize($_POST['middle_name'] ?? '');

        // ADDRESS - PRESENT
        $data['province']       = Security::sanitize($_POST['province'] ?? '');
        $data['city']           = Security::sanitize($_POST['city'] ?? '');
        $data['barangay']       = Security::sanitize($_POST['barangay'] ?? '');
        $data['present_street'] = Security::sanitize($_POST['present_street'] ?? '');

        // ADDRESS - PERMANENT
        $data['permanent_province'] = Security::sanitize($_POST['permanent_province'] ?? '');
        $data['permanent_city']     = Security::sanitize($_POST['permanent_city'] ?? '');
        $data['permanent_barangay'] = Security::sanitize($_POST['permanent_barangay'] ?? '');
        $data['permanent_street']   = Security::sanitize($_POST['permanent_street'] ?? '');

        // PLACE OF BIRTH
        $data['birth_province'] = Security::sanitize($_POST['birth_province'] ?? '');
        $data['birth_city']     = Security::sanitize($_POST['birth_city'] ?? '');

        // Combined addresses
        $present_parts = [];
        if (!empty($data['present_street'])) $present_parts[] = $data['present_street'];
        if (!empty($data['barangay']))       $present_parts[] = 'Brgy. ' . $data['barangay'];
        if (!empty($data['city']))           $present_parts[] = $data['city'];
        if (!empty($data['province']))       $present_parts[] = $data['province'];
        $data['address']         = implode(', ', $present_parts);
        $data['present_address'] = $data['address'];

        $permanent_parts = [];
        if (!empty($data['permanent_street']))   $permanent_parts[] = $data['permanent_street'];
        if (!empty($data['permanent_barangay'])) $permanent_parts[] = 'Brgy. ' . $data['permanent_barangay'];
        if (!empty($data['permanent_city']))     $permanent_parts[] = $data['permanent_city'];
        if (!empty($data['permanent_province'])) $permanent_parts[] = $data['permanent_province'];
        $data['permanent_address'] = implode(', ', $permanent_parts);

        $birth_parts = [];
        if (!empty($data['birth_city']))     $birth_parts[] = $data['birth_city'];
        if (!empty($data['birth_province'])) $birth_parts[] = $data['birth_province'];
        $data['place_of_birth'] = implode(', ', $birth_parts);

        $data['street'] = $data['present_street'];

        // CONTACT
        $data['contact_number']   = Security::sanitize($_POST['contact_number'] ?? '');
        $data['alternate_number'] = Security::sanitize($_POST['alternate_number'] ?? '');
        $data['email']            = Security::sanitize($_POST['email'] ?? '');

        // PERSONAL
        $data['birth_date']   = !empty($_POST['birth_date']) ? $_POST['birth_date'] : null;
        $data['age']          = !empty($_POST['age']) ? intval($_POST['age']) : null;
        $data['gender']       = Security::sanitize($_POST['gender'] ?? '');
        $data['civil_status'] = Security::sanitize($_POST['civil_status'] ?? '');
        $data['religion']     = Security::sanitize($_POST['religion'] ?? '');

        // FAMILY
        $data['father_fname'] = Security::sanitize($_POST['father_fname'] ?? '');
        $data['father_mname'] = Security::sanitize($_POST['father_mname'] ?? '');
        $data['father_lname'] = Security::sanitize($_POST['father_lname'] ?? '');

        $data['mother_fname'] = Security::sanitize($_POST['mother_fname'] ?? '');
        $data['mother_mname'] = Security::sanitize($_POST['mother_mname'] ?? '');
        $data['mother_lname'] = Security::sanitize($_POST['mother_lname'] ?? '');

        $data['spouse_fname'] = Security::sanitize($_POST['spouse_fname'] ?? '');
        $data['spouse_mname'] = Security::sanitize($_POST['spouse_mname'] ?? '');
        $data['spouse_lname'] = Security::sanitize($_POST['spouse_lname'] ?? '');
        $data['spouse_age']   = !empty($_POST['spouse_age']) ? intval($_POST['spouse_age']) : null;

        // CHILDREN
        for ($i = 1; $i <= 4; $i++) {
            $data["child{$i}_fname"] = Security::sanitize($_POST["child{$i}_fname"] ?? '');
            $data["child{$i}_mname"] = Security::sanitize($_POST["child{$i}_mname"] ?? '');
            $data["child{$i}_lname"] = Security::sanitize($_POST["child{$i}_lname"] ?? '');
            $data["child{$i}_age"]   = !empty($_POST["child{$i}_age"]) ? intval($_POST["child{$i}_age"]) : null;

            $child_name = trim(
                ($data["child{$i}_fname"] ?? '') . ' ' .
                ($data["child{$i}_mname"] ?? '') . ' ' .
                ($data["child{$i}_lname"] ?? '')
            );
            $data["child{$i}_name"] = !empty($child_name) ? $child_name : null;
        }

        // REFERENCES
        $data['ref1_name']    = Security::sanitize($_POST['ref1_name'] ?? '');
        $data['ref1_contact'] = Security::sanitize($_POST['ref1_contact'] ?? '');
        $data['ref2_name']    = Security::sanitize($_POST['ref2_name'] ?? '');
        $data['ref2_contact'] = Security::sanitize($_POST['ref2_contact'] ?? '');

        // CHAPTER
        $data['chapter']              = Security::sanitize($_POST['chapter'] ?? '');
        $data['group_name']           = Security::sanitize($_POST['group_name'] ?? '');
        $data['leader']               = Security::sanitize($_POST['leader'] ?? '');
        $data['coordinator']          = Security::sanitize($_POST['coordinator'] ?? '');
        $data['chairman']             = Security::sanitize($_POST['chairman'] ?? '');
        $data['screening_officer']    = Security::sanitize($_POST['screening_officer'] ?? '');
        $data['screening_date']       = !empty($_POST['screening_date']) ? $_POST['screening_date'] : null;
        $data['approved_by']          = Security::sanitize($_POST['approved_by'] ?? '');
        $data['date_joined']          = !empty($_POST['date_joined']) ? $_POST['date_joined'] : date('Y-m-d');
        $data['date_registered']      = !empty($_POST['date_registered']) ? $_POST['date_registered'] : date('Y-m-d');
        $data['monthly_contribution'] = isset($_POST['monthly_contribution']) && $_POST['monthly_contribution'] !== ''
            ? floatval($_POST['monthly_contribution']) : 100.00;

        // BENEFICIARY
        $data['beneficiary_name']     = Security::sanitize($_POST['beneficiary_name'] ?? '');
        $data['beneficiary_address']  = Security::sanitize($_POST['beneficiary_address'] ?? '');
        $data['beneficiary_relation'] = Security::sanitize($_POST['beneficiary_relation'] ?? '');
        $data['beneficiary_age']      = !empty($_POST['beneficiary_age']) ? intval($_POST['beneficiary_age']) : null;
        $data['beneficiary_contact']  = Security::sanitize($_POST['beneficiary_contact'] ?? '');

        // DOCUMENTS checkboxes
        $data['medical_certificate'] = isset($_POST['medical_certificate']) ? 1 : 0;
        $data['birth_certificate']   = isset($_POST['birth_certificate']) ? 1 : 0;

        // ============================================================
        // HANDLE FILE UPLOADS (plain $_FILES — no more AJAX)
        // ============================================================
        $photoUpload = handleUpload('registration_photo',       'pending');
        $medUpload   = handleUpload('medical_certificate_file', 'pending');
        $birthUpload = handleUpload('birth_certificate_file',   'pending');

        // Collect any upload errors
        $uploadErrors = [];
        if ($photoUpload['status'] === 'error') $uploadErrors[] = 'ID Photo: ' . $photoUpload['message'];
        if ($medUpload['status']   === 'error') $uploadErrors[] = 'Medical Certificate: ' . $medUpload['message'];
        if ($birthUpload['status'] === 'error') $uploadErrors[] = 'Birth Certificate: ' . $birthUpload['message'];

        $data['registration_photo']        = $photoUpload['path'];
        $data['medical_certificate_file']  = $medUpload['path'];
        $data['birth_certificate_file']    = $birthUpload['path'];

        // If a file was uploaded, ensure its corresponding checkbox is set
        if ($medUpload['status']   === 'ok') $data['medical_certificate'] = 1;
        if ($birthUpload['status'] === 'ok') $data['birth_certificate']   = 1;

        // ACCOUNT — for existing joined accounts, credentials are already set
        $data['username'] = $prefill_username;
        $data['email']    = $prefill_email;
        $password         = $_POST['password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        // VALIDATION
        $required_fields = [
            'first_name', 'last_name', 'contact_number', 'username', 'email',
            'chapter', 'beneficiary_name', 'beneficiary_relation',
            'province', 'city', 'barangay',
            'permanent_province', 'permanent_city', 'permanent_barangay',
            'birth_province', 'birth_city', 'religion'
        ];
        $missing_fields = [];
        foreach ($required_fields as $field) {
            if (empty($data[$field])) {
                $missing_fields[] = str_replace('_', ' ', ucfirst($field));
            }
        }

        if (!empty($uploadErrors)) {
            $error = 'File upload error: ' . implode('; ', $uploadErrors);
            $form_data = $_POST;
        } elseif (!empty($missing_fields)) {
            $error = 'Please fill all required fields: ' . implode(', ', $missing_fields);
            $form_data = $_POST;
        } elseif ($data['monthly_contribution'] < 100) {
            $error = 'Monthly contribution must be at least ₱100.00.';
            $form_data = $_POST;
        } elseif (!$existing_account && strlen($password) < 6) {
            $error = 'Password must be at least 6 characters';
            $form_data = $_POST;
        } elseif (!$existing_account && $password !== $confirm_password) {
            $error = 'Passwords do not match';
            $form_data = $_POST;
        } else {
            // Uniqueness check — only for brand-new applications
            $dup_error = false;
            if (!$existing_app) {
                $check_sql = "SELECT 'pending' as source, id FROM pending_users
                              WHERE (username = ? OR email = ?)
                              UNION
                              SELECT 'users' as source, user_id as id FROM users
                              WHERE (username = ? OR email = ?) AND user_id <> ?";
                $existing_rows = $db->getAll(
                    $check_sql,
                    [$data['username'], $data['email'], $data['username'], $data['email'], $current_user['user_id']],
                    'ssssi'
                );
                if (!empty($existing_rows)) {
                    $error = 'Username or email already exists';
                    $form_data = $_POST;
                    $dup_error = true;
                }
            }

            if (!$dup_error) {
                // Member code: keep existing one, or use session-cached one, or generate
                if ($existing_app && !empty($existing_app['member_code'])) {
                    $member_code = $existing_app['member_code'];
                } elseif (!empty($_SESSION['pending_member_code'])) {
                    $member_code = $_SESSION['pending_member_code'];
                } else {
                    $member_code = date('Y') . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
                }

                // For existing accounts, keep the users table's password hash.
                $hashed_password = $existing_account
                    ? $user_info['password']
                    : password_hash($password, PASSWORD_DEFAULT);

                $fields = [
                    'member_code', 'first_name', 'last_name', 'middle_name',
                    'address', 'present_address', 'permanent_address',
                    'barangay', 'city', 'province', 'street', 'present_street',
                    'permanent_province', 'permanent_city', 'permanent_barangay', 'permanent_street',
                    'birth_province', 'birth_city',
                    'contact_number', 'alternate_number', 'email',
                    'birth_date', 'place_of_birth', 'age', 'gender', 'civil_status', 'religion',
                    'father_fname', 'father_mname', 'father_lname',
                    'mother_fname', 'mother_mname', 'mother_lname',
                    'spouse_fname', 'spouse_mname', 'spouse_lname', 'spouse_age',
                    'child1_fname', 'child1_mname', 'child1_lname', 'child1_name', 'child1_age',
                    'child2_fname', 'child2_mname', 'child2_lname', 'child2_name', 'child2_age',
                    'child3_fname', 'child3_mname', 'child3_lname', 'child3_name', 'child3_age',
                    'child4_fname', 'child4_mname', 'child4_lname', 'child4_name', 'child4_age',
                    'ref1_name', 'ref1_contact', 'ref2_name', 'ref2_contact',
                    'chapter', 'group_name', 'leader', 'coordinator', 'chairman',
                    'screening_officer', 'screening_date', 'approved_by',
                    'date_joined', 'date_registered', 'monthly_contribution',
                    'beneficiary_name', 'beneficiary_address', 'beneficiary_relation',
                    'beneficiary_age', 'beneficiary_contact',
                    'medical_certificate', 'medical_certificate_file',
                    'birth_certificate', 'birth_certificate_file',
                    'registration_photo',
                    'username', 'password', 'role_requested', 'status'
                ];

                $insert_params = [
                    $member_code,
                    $data['first_name'], $data['last_name'], $data['middle_name'],
                    $data['address'], $data['present_address'], $data['permanent_address'],
                    $data['barangay'], $data['city'], $data['province'], $data['street'], $data['present_street'],
                    $data['permanent_province'], $data['permanent_city'], $data['permanent_barangay'], $data['permanent_street'],
                    $data['birth_province'], $data['birth_city'],
                    $data['contact_number'], $data['alternate_number'], $data['email'],
                    $data['birth_date'], $data['place_of_birth'], $data['age'], $data['gender'], $data['civil_status'], $data['religion'],
                    $data['father_fname'], $data['father_mname'], $data['father_lname'],
                    $data['mother_fname'], $data['mother_mname'], $data['mother_lname'],
                    $data['spouse_fname'], $data['spouse_mname'], $data['spouse_lname'], $data['spouse_age'],
                    $data['child1_fname'], $data['child1_mname'], $data['child1_lname'], $data['child1_name'], $data['child1_age'],
                    $data['child2_fname'], $data['child2_mname'], $data['child2_lname'], $data['child2_name'], $data['child2_age'],
                    $data['child3_fname'], $data['child3_mname'], $data['child3_lname'], $data['child3_name'], $data['child3_age'],
                    $data['child4_fname'], $data['child4_mname'], $data['child4_lname'], $data['child4_name'], $data['child4_age'],
                    $data['ref1_name'], $data['ref1_contact'], $data['ref2_name'], $data['ref2_contact'],
                    $data['chapter'], $data['group_name'], $data['leader'], $data['coordinator'], $data['chairman'],
                    $data['screening_officer'], $data['screening_date'], $data['approved_by'],
                    $data['date_joined'], $data['date_registered'], $data['monthly_contribution'],
                    $data['beneficiary_name'], $data['beneficiary_address'], $data['beneficiary_relation'],
                    $data['beneficiary_age'], $data['beneficiary_contact'],
                    $data['medical_certificate'], $data['medical_certificate_file'],
                    $data['birth_certificate'], $data['birth_certificate_file'],
                    $data['registration_photo'],
                    $data['username'], $hashed_password, 'viewer', 'pending'
                ];

                $saved = false;

                if ($existing_app) {
                    // UPDATE existing pending_users row (re-submission after rejection)
                    $set_parts = [];
                    $vals = [];
                    $types_str = '';

                    foreach ($fields as $idx => $field) {
                        if ($field === 'member_code') continue;
                        if ($field === 'status') continue;   // handled explicitly below

                        $set_parts[] = "$field = ?";
                        $v = $insert_params[$idx];
                        $vals[] = $v;
                        if (is_int($v)) $types_str .= 'i';
                        elseif (is_float($v)) $types_str .= 'd';
                        else $types_str .= 's';
                    }

                    $set_parts[] = "status = 'pending'";
                    $set_parts[] = "user_id = ?";
                    $vals[] = $current_user['user_id'];
                    $types_str .= 'i';
                    $set_parts[] = "requested_at = NOW()";

                    $vals[] = $existing_app['id'];
                    $types_str .= 'i';

                    $update_sql = "UPDATE pending_users SET " . implode(', ', $set_parts) . " WHERE id = ?";
                    $saved = $db->execute($update_sql, $vals, $types_str);
                    $log_action = 'REGISTRATION_RESUBMITTED';
                    $log_msg = "Resubmitted: " . $data['username'];
                } else {
                    // INSERT new pending_users row (first submission)
                    $fields[] = 'user_id';
                    $insert_params[] = $current_user['user_id'];

                    $placeholders = array_fill(0, count($fields), '?');
                    $insert_types = '';
                    foreach ($insert_params as $v) {
                        if (is_int($v)) $insert_types .= 'i';
                        elseif (is_float($v)) $insert_types .= 'd';
                        else $insert_types .= 's';
                    }

                    $insert_sql = "INSERT INTO pending_users (" . implode(', ', $fields) . ")
                                   VALUES (" . implode(', ', $placeholders) . ")";
                    $saved = $db->execute($insert_sql, $insert_params, $insert_types);
                    $log_action = 'REGISTRATION_SUBMITTED';
                    $log_msg = "New registration: " . $data['username'];
                }

                if ($saved) {
                    // Flip the account state
                    $db->execute(
                        "UPDATE users SET account_status = 'pending_approval' WHERE user_id = ?",
                        [$current_user['user_id']], 'i'
                    );

                    unset($_SESSION['temp_registration_files'], $_SESSION['temp_registration_photo']);
                    unset($_SESSION['pending_member_code']);
                    Security::logEvent($log_action, $log_msg);

                    header('Location: user/welcome.php?submitted=1');
                    exit();
                } else {
                    // Clean up uploaded files on failure
                    foreach ([$data['registration_photo'], $data['medical_certificate_file'], $data['birth_certificate_file']] as $rel) {
                        if (!empty($rel)) {
                            $fullPath = __DIR__ . '/' . $rel;
                            if (is_file($fullPath)) @unlink($fullPath);
                        }
                    }
                    $error = 'Registration failed. Please try again.';
                    $form_data = $_POST;
                }
            }
        }
    }
}

// ============================================================
// Preview member code (stable across reloads)
// ============================================================
if ($existing_app && !empty($existing_app['member_code'])) {
    $generated_member_code = $existing_app['member_code'];
} else {
    if (empty($_SESSION['pending_member_code'])) {
        $_SESSION['pending_member_code'] = date('Y') . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
    }
    $generated_member_code = $_SESSION['pending_member_code'];
}

$csrf_token = Security::generateCSRFToken();

if (isset($_GET['debug'])) {
    echo "<h2>Debug Information</h2>";
    $db = Database::getInstance();
    $pending = $db->getAll("SELECT * FROM pending_users ORDER BY id DESC LIMIT 5");
    echo "<pre>"; print_r($pending); echo "</pre>";
    exit;
}

include 'register.template.php';
?>