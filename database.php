<?php
/**
 * Database Configuration and Connection
 * Handles database connection for cache storage
 */

// Prevent direct access
if (!defined('ALLOW_INCLUDE')) {
    http_response_code(403);
    die('Direct access not allowed');
}

class Database {
    private static $instance = null;
    private $pdo;
    
    private function __construct() {
        $config = require __DIR__ . '/config.php';
        
        $dbConfig = $config['database'] ?? [
            'host' => 'localhost',
            'port' => 3306,
            'dbname' => 'menu_cache',
            'username' => 'root',
            'password' => '',
            'charset' => 'utf8mb4'
        ];
        
        $port = $dbConfig['port'] ?? 3306;
        $host = $dbConfig['host'];
        $dbname = $dbConfig['dbname'];
        
        // Connect to the database (database should already exist, created via SQL file)
        // Include port in DSN if it's not the default 3306
        if ($port != 3306) {
            $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset={$dbConfig['charset']}";
        } else {
            $dsn = "mysql:host={$host};dbname={$dbname};charset={$dbConfig['charset']}";
        }
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        
        try {
            $this->pdo = new PDO($dsn, $dbConfig['username'], $dbConfig['password'], $options);
        } catch (PDOException $e) {
            error_log('Database connection failed: ' . $e->getMessage());
            throw new Exception('Database connection failed. Please check your database configuration.');
        }
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function getConnection() {
        return $this->pdo;
    }
    
    /**
     * Initialize database tables
     */
    public function initialize() {
        $sql = "CREATE TABLE IF NOT EXISTS cache (
            cache_key VARCHAR(255) PRIMARY KEY,
            cache_data LONGTEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            expires_at TIMESTAMP NOT NULL,
            INDEX idx_expires_at (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        try {
            $this->pdo->exec($sql);
            return true;
        } catch (PDOException $e) {
            error_log('Failed to create cache table: ' . $e->getMessage());
            return false;
        }
    }
}
