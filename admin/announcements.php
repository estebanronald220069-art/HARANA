<?php
// admin/announcements.php
require_once '../includes/config.php';
require_once '../includes/db_connection.php';
require_once '../includes/security.php';
require_once '../includes/auth.php';
require_once '../includes/user_functions.php';

$auth->requireLogin();
$auth->requireRole('admin');
$current_user = $auth->getCurrentUser();

$db = Database::getInstance();
$message = '';
$error = '';

// Handle new announcement
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_announcement'])) {
    if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token';
    } else {
        $title = Security::sanitize($_POST['title'] ?? '');
        $message_text = Security::sanitize($_POST['message'] ?? '');
        $type = Security::sanitize($_POST['type'] ?? 'announcement');
        
        if (empty($title) || empty($message_text)) {
            $error = 'Please fill in all fields';
        } else {
            $count = createNotificationForAll($db, $title, $message_text, $type);
            if ($count > 0) {
                $message = "Announcement sent to $count members successfully!";
                Security::logEvent('ANNOUNCEMENT_SENT', "Sent announcement: $title");
            } else {
                $error = 'Failed to send announcement';
            }
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
    <title>Send Announcement - <?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body {
            background: #f4f7fc;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .container {
            max-width: 800px;
            margin: 50px auto;
        }
        
        .card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        
        .card-header {
            background: linear-gradient(135deg, #375a7f, #2c4a6b);
            color: white;
            border-radius: 12px 12px 0 0 !important;
        }
        
        .form-control, .form-select {
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 10px 12px;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: #375a7f;
            box-shadow: 0 0 0 0.2rem rgba(55,90,127,0.1);
        }
        
        .btn-send {
            background: #375a7f;
            color: white;
            border: none;
            padding: 12px;
            border-radius: 8px;
            font-weight: 600;
            width: 100%;
        }
        
        .btn-send:hover {
            background: #2c4a6b;
            transform: translateY(-2px);
        }
        
        .preview-box {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            margin-top: 15px;
            border-left: 4px solid #375a7f;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-bullhorn me-2"></i>Send Announcement to All Members</h5>
            </div>
            <div class="card-body">
                <?php if ($message): ?>
                    <div class="alert alert-success"><?php echo $message; ?></div>
                <?php endif; ?>
                <?php if ($error): ?>
                    <div class="alert alert-danger"><?php echo $error; ?></div>
                <?php endif; ?>
                
                <form method="POST" id="announcementForm">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    
                    <div class="mb-3">
                        <label class="form-label">Announcement Type</label>
                        <select name="type" class="form-select" required>
                            <option value="announcement">General Announcement</option>
                            <option value="event">Event Announcement</option>
                            <option value="reminder">Reminder</option>
                            <option value="system">System Update</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Title</label>
                        <input type="text" name="title" class="form-control" required 
                               placeholder="e.g., Monthly General Assembly">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Message</label>
                        <textarea name="message" class="form-control" rows="5" required 
                                  placeholder="Enter your announcement message here..."></textarea>
                    </div>
                    
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        This announcement will be sent to all active members.
                    </div>
                    
                    <button type="submit" name="send_announcement" class="btn-send">
                        <i class="fas fa-paper-plane me-2"></i>Send Announcement
                    </button>
                </form>
                
                <div class="preview-box" id="previewBox">
                    <strong>Preview:</strong>
                    <div id="previewContent">Your message preview will appear here...</div>
                </div>
            </div>
        </div>
        
        <div class="text-center mt-3">
            <a href="dashboard.php" class="text-decoration-none">
                <i class="fas fa-arrow-left me-1"></i>Back to Dashboard
            </a>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Live preview
        const titleInput = document.querySelector('[name="title"]');
        const messageInput = document.querySelector('[name="message"]');
        const typeSelect = document.querySelector('[name="type"]');
        const previewContent = document.getElementById('previewContent');
        
        function updatePreview() {
            const title = titleInput.value || '[Title]';
            const message = messageInput.value || '[Message]';
            const type = typeSelect.options[typeSelect.selectedIndex]?.text || 'Announcement';
            
            previewContent.innerHTML = `
                <div class="fw-bold mb-2">${escapeHtml(title)}</div>
                <div class="mb-2">${escapeHtml(message).replace(/\n/g, '<br>')}</div>
                <div class="small text-muted">Type: ${type}</div>
            `;
        }
        
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        titleInput.addEventListener('input', updatePreview);
        messageInput.addEventListener('input', updatePreview);
        typeSelect.addEventListener('change', updatePreview);
        updatePreview();
    </script>
</body>
</html>