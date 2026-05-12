<?php
// setup.php - RUN THIS ONLY ONCE!
require_once 'includes/config.php';
require_once 'includes/db_connection.php';
require_once 'includes/security.php';

// Check if admin already exists
$db = Database::getInstance();
$admin = $db->getSingle("SELECT * FROM users WHERE username = 'admin'");

if (!$admin) {
    // Create admin user
    $password = 'Admin123!'; // CHANGE THIS!
    $hash = Security::hashPassword($password);
    
    $db->execute(
        "INSERT INTO users (username, password, full_name, email, role) VALUES (?, ?, ?, ?, ?)",
        ['admin', $hash, 'System Administrator', 'admin@harana.com', 'admin'],
        'sssss'
    );
    
    echo "Admin user created successfully!\n";
    echo "Username: admin\n";
    echo "Password: Admin123!\n";
    echo "Please change this password after first login!\n";
} else {
    echo "Admin user already exists.\n";
}

// Create upload directories
$dirs = [
    UPLOAD_PATH,
    UPLOAD_PATH . 'receipts/',
    UPLOAD_PATH . 'profiles/',
    LOG_PATH,
    __DIR__ . '/backups/'
];

foreach ($dirs as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
        echo "Created directory: $dir\n";
    }
}

echo "Setup complete!\n";
?>