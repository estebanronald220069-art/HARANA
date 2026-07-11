<?php
namespace Harana\Config;

class Database {
    private static $instance = null;
    private $config;
    
    private function __construct() {
        $this->config = require __DIR__ . '/../../config/database.php';
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
            self::$instance->connect();
        }
        return self::$instance;
    }
    
    private function connect() {
        $dsn = "mysql:host={$this->config['host']};dbname={$this->config['dbname']};charset={$this->config['charset']}";
        
        try {
            $pdo = new \PDO($dsn, $this->config['username'], $this->config['password']);
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            return $pdo;
        } catch (\PDOException $e) {
            throw new \Exception("Database connection failed: " . $e->getMessage());
        }
    }
}