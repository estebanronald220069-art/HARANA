<?php
// check_lock_status.php
require_once 'includes/config.php';
require_once 'includes/db_connection.php';

$db = Database::getInstance();
$username = 'admin'; // Change to your username

$user = $db->getSingle(
    "SELECT user_id, username, login_attempts, locked_until, 
            DATE_FORMAT(locked_until, '%Y-%m-%d %H:%i:%s') as lock_time 
     FROM users WHERE username = ?",
    [$username],
    's'
);

echo "<h2>Lock Status for: $username</h2>";

if ($user) {
    echo "<pre>";
    print_r($user);
    echo "</pre>";
    
    $now = date('Y-m-d H:i:s');
    echo "Current time: $now<br>";
    
    if ($user['locked_until'] && $user['locked_until'] > $now) {
        $remaining = strtotime($user['locked_until']) - time();
        echo "<p style='color:red'>🔴 ACCOUNT LOCKED!</p>";
        echo "Locked until: " . $user['locked_until'] . "<br>";
        echo "Time remaining: " . ceil($remaining/60) . " minutes<br>";
    } else {
        echo "<p style='color:green'>✅ Account is NOT locked</p>";
    }
    
    echo "<p>Login attempts: " . $user['login_attempts'] . "</p>";
} else {
    echo "User not found!";
}

// Add reset button
echo "<form method='POST'>";
echo "<input type='hidden' name='reset' value='1'>";
echo "<button type='submit'>Reset Lock</button>";
echo "</form>";

if ($_POST['reset'] ?? false) {
    $db->execute(
        "UPDATE users SET login_attempts = 0, locked_until = NULL WHERE username = ?",
        [$username],
        's'
    );
    echo "<p style='color:green'>✅ Lock reset! <a href=''>Refresh</a></p>";
}
?>