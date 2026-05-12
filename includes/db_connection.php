<?php
// includes/db_connection.php
require_once __DIR__ . '/config.php';

class Database {
    private static $instance = null;
    private $conn;
    
    private function __construct() {
        try {
            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
            $this->conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
            $this->conn->set_charset('utf8mb4');
        } catch (Exception $e) {
            error_log("Database connection failed: " . $e->getMessage());
            die("System temporarily unavailable. Please try again later.");
        }
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function getConnection() {
        return $this->conn;
    }
    
    // Safe query with prepared statements
    public function query($sql, $params = [], $types = '') {
        try {
            $stmt = $this->conn->prepare($sql);
            
            if (!$stmt) {
                throw new Exception("Prepare failed: " . $this->conn->error);
            }
            
            if (!empty($params)) {
                if (empty($types)) {
                    $types = str_repeat('s', count($params));
                }
                $stmt->bind_param($types, ...$params);
            }
            
            $stmt->execute();
            return $stmt;
        } catch (Exception $e) {
            error_log("Query failed: " . $e->getMessage() . " SQL: " . $sql);
            return false;
        }
    }
    
    // Get single record
    public function getSingle($sql, $params = [], $types = '') {
        $stmt = $this->query($sql, $params, $types);
        if ($stmt) {
            $result = $stmt->get_result();
            return $result->fetch_assoc();
        }
        return null;
    }
    
    // Get multiple records
    public function getAll($sql, $params = [], $types = '') {
        $stmt = $this->query($sql, $params, $types);
        if ($stmt) {
            $result = $stmt->get_result();
            return $result->fetch_all(MYSQLI_ASSOC);
        }
        return [];
    }
    
    // Insert and return ID
    public function insert($sql, $params = [], $types = '') {
        $stmt = $this->query($sql, $params, $types);
        return $stmt ? $this->conn->insert_id : false;
    }
    
    // Execute update/delete
    public function execute($sql, $params = [], $types = '') {
        $stmt = $this->query($sql, $params, $types);
        return $stmt ? $stmt->affected_rows : false;
    }
    
    public function escape($string) {
        return $this->conn->real_escape_string($string);
    }
}

// Global database instance
$db = Database::getInstance();
?>