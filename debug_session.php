<?php
// debug_session.php
require_once 'includes/config.php';
require_once 'includes/db_connection.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

echo "<h1>Session Debug Information</h1>";
echo "<h2>Current Session Data:</h2>";
echo "<pre>";
print_r($_SESSION);
echo "</pre>";

// Check the database for this user
if (isset($_SESSION['user_id'])) {
    $db = Database::getInstance();
    $user = $db->getSingle("SELECT user_id, username, role FROM users WHERE user_id = ?", [$_SESSION['user_id']], 'i');
    
    echo "<h2>User from Database:</h2>";
    echo "<pre>";
    print_r($user);
    echo "</pre>";
    
    echo "<h2>Comparison:</h2>";
    echo "Session role: " . ($_SESSION['role'] ?? 'NOT SET') . "<br>";
    echo "Database role: " . ($user['role'] ?? 'NOT FOUND') . "<br>";
    
    if ($_SESSION['role'] !== $user['role']) {
        echo "<p style='color:red'><strong>⚠️ MISMATCH! Session role is different from database role!</strong></p>";
        
        // Fix it temporarily
        echo "<form method='POST'>";
        echo "<input type='hidden' name='fix' value='1'>";
        echo "<button type='submit'>Fix Session Role (Set to: " . $user['role'] . ")</button>";
        echo "</form>";
        
        if (isset($_POST['fix'])) {
            $_SESSION['role'] = $user['role'];
            echo "<p style='color:green'>✅ Session role fixed! <a href='debug_session.php'>Refresh</a></p>";
        }
    } else {
        echo "<p style='color:green'>✅ Session role matches database role</p>";
    }
} else {
    echo "<p>No user logged in. Please log in first.</p>";
    echo "<a href='index.php'>Go to Login</a>";
}

echo "<h2>Session ID:</h2>";
echo session_id() . "<br>";

echo "<h2>Session Save Path:</h2>";
echo session_save_path() . "<br>";

echo "<h2>Cookie Info:</h2>";
echo "<pre>";
print_r($_COOKIE);
echo "</pre>";

echo "<h2>Links:</h2>";
echo "<a href='logout.php'>Logout</a><br>";
echo "<a href='index.php'>Go to Login</a><br>";
echo "<a href='admin/dashboard.php'>Go to Admin Dashboard</a><br>";
echo "<a href='user/dashboard.php'>Go to User Dashboard</a><br>";
?>