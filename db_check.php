<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db_connection.php';

$db = Database::getInstance();
$conn = $db->getConnection();

// 1. What DB are we actually connected to?
$row = $conn->query("SELECT DATABASE() AS db")->fetch_assoc();
echo "Connected to DB: " . htmlspecialchars($row['db']) . "<br><br>";

// 2. What tables actually exist?
echo "Tables in this DB:<br>";
$tables = $conn->query("SHOW TABLES");
while ($t = $tables->fetch_array()) {
    echo " - " . htmlspecialchars($t[0]) . "<br>";
}

// 3. Does the members table exist, and how?
echo "<br>SHOW TABLES LIKE 'members': ";
$r = $conn->query("SHOW TABLES LIKE 'members'");
echo $r->num_rows ? "YES" : "NO";
echo "<br>";

echo "SHOW TABLES LIKE 'Members': ";
$r = $conn->query("SHOW TABLES LIKE 'Members'");
echo $r->num_rows ? "YES" : "NO";
echo "<br>";