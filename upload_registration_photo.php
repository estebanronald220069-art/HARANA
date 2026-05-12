<?php
// upload_registration_photo.php - Handle temporary photo upload during registration
require_once 'includes/config.php';
require_once 'includes/db_connection.php';
require_once 'includes/security.php';

session_start();

$response = ['success' => false, 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $response['message'] = 'Invalid security token.';
    } else {
        // Check if file was uploaded
        if (isset($_FILES['registration_photo']) && $_FILES['registration_photo']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['registration_photo'];
            
            // Validate file type
            $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $max_size = 10 * 1024 * 1024; // 10MB
            
            if (!in_array($file['type'], $allowed_types)) {
                $response['message'] = 'Only JPG, PNG, GIF, and WEBP images are allowed.';
            } elseif ($file['size'] > $max_size) {
                $response['message'] = 'File size must be less than 10MB.';
            } else {
                // Get image dimensions
                $image_info = getimagesize($file['tmp_name']);
                if ($image_info) {
                    $width = $image_info[0];
                    $height = $image_info[1];
                    
                    // Check minimum resolution (300x300)
                    if ($width < 300 || $height < 300) {
                        $response['message'] = 'Image resolution too low. Minimum 300x300 pixels required.';
                    } 
                    // Check 2x2 ratio (allow slight tolerance - between 0.9 and 1.1)
                    elseif (($width / $height) < 0.9 || ($width / $height) > 1.1) {
                        $response['message'] = 'Image must be square (1:1 ratio). Please upload a 2x2 ID picture.';
                    } else {
                        // Create upload directory if it doesn't exist
                        $upload_dir = 'uploads/temp/';
                        if (!file_exists($upload_dir)) {
                            mkdir($upload_dir, 0777, true);
                        }
                        
                        // Generate unique filename
                        $temp_session_id = session_id();
                        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
                        $filename = 'temp_' . $temp_session_id . '_' . time() . '.' . $extension;
                        $filepath = $upload_dir . $filename;
                        
                        // Move uploaded file
                        if (move_uploaded_file($file['tmp_name'], $filepath)) {
                            // Store in session temporarily
                            $_SESSION['temp_registration_photo'] = $filename;
                            
                            $response['success'] = true;
                            $response['message'] = 'Photo uploaded successfully!';
                            $response['photo_preview'] = $upload_dir . $filename;
                        } else {
                            $response['message'] = 'Failed to upload file.';
                        }
                    }
                } else {
                    $response['message'] = 'Invalid image file.';
                }
            }
        } else {
            $response['message'] = 'No file uploaded or upload error.';
        }
    }
}

header('Content-Type: application/json');
echo json_encode($response);
?>