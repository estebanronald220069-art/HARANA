<?php
require_once 'includes/config.php';
require_once 'includes/db_connection.php';
require_once 'includes/security.php';

$db = Database::getInstance();

// New password you want to set
$new_password = 'Jd123!';
$hashed = password_hash($new_password, PASSWORD_DEFAULT);

// Update in users table
$result1 = $db->execute(
    "UPDATE users SET password = ? WHERE username = 'JD'",
    [$hashed],
    's'
);

// Also update in members table if it has password field
$result2 = $db->execute(
    "UPDATE members SET password = ? WHERE username = 'JD'",
    [$hashed],
    's'
);

echo "Password for JD has been reset to: $new_password<br>";
echo "Users table updated: " . ($result1 ? 'YES' : 'NO') . "<br>";
echo "Members table updated: " . ($result2 ? 'YES' : 'NO') . "<br>";

// Verify it worked
$check = $db->getSingle("SELECT password FROM users WHERE username = 'JD'");
echo "<br>New password hash: " . $check['password'] . "<br>";
echo "Verification test: " . (password_verify($new_password, $check['password']) ? '✅ WORKS!' : '❌ FAILED!');
?>