<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../vendor/autoload.php';

echo "<h1>HARANA Phase 1 Test</h1>";

try {
    $db = \Harana\Config\Database::getInstance();
    
                                          // Check if $db is a PDO object
    if ($db instanceof \PDO) {
        echo "<p style='color:green;font-size:18px;'>✅ Database connected successfully!</p>";
        echo "<p>Driver: " . $db->getAttribute(\PDO::ATTR_DRIVER_NAME) . "</p>";
        echo "<p>Server: " . $db->getAttribute(\PDO::ATTR_SERVER_VERSION) . "</p>";
        echo "<h2 style='color:green;'>✅ ALL TESTS PASSED!</h2>";
        echo "<p>You can now delete this file (public/test.php)</p>";
    } else {
        echo "<p style='color:orange;'>⚠️ Database connected but not returning PDO object</p>";
    }
} catch (Exception $e) {
    echo "<h2 style='color:red;'>❌ TEST FAILED</h2>";
    echo "<p><strong>Error:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p><strong>File:</strong> " . $e->getFile() . ":" . $e->getLine() . "</p>";
}