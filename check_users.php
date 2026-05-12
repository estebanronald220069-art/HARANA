<?php
require_once 'includes/config.php';
require_once 'includes/db_connection.php';

$db = Database::getInstance();

echo "<h2>Checking Users in Database</h2>";

// Check if users table exists
$tables = $db->getAll("SHOW TABLES");
echo "<h3>Tables in database:</h3>";
echo "<pre>";
print_r($tables);
echo "</pre>";

// Check users
$users = $db->getAll("SELECT user_id, username, email, role FROM users");
echo "<h3>Users found:</h3>";
if (empty($users)) {
    echo "<p style='color:red'>No users found in database!</p>";
} else {
    echo "<pre>";
    print_r($users);
    echo "</pre>";
}

// Check admin specifically
$admin = $db->getSingle("SELECT * FROM users WHERE username = 'admin'");
echo "<h3>Admin user details:</h3>";
if ($admin) {
    echo "<pre>";
    print_r($admin);
    echo "</pre>";
    echo "<p>Password hash: " . $admin['password'] . "</p>";
    
    // Test password
    $test_password = 'Admin123!';
    echo "<p>Testing password 'Admin123!': ";
    if (password_verify($test_password, $admin['password'])) {
        echo "<span style='color:green'>✓ CORRECT</span>";
    } else {
        echo "<span style='color:red'>✗ WRONG</span>";
    }
    echo "</p>";
} else {
    echo "<p style='color:red'>Admin user NOT FOUND!</p>";
}
?>