<?php
/**
 * Cache Management System
 * Handles caching of API responses in database to reduce API calls and improve performance
 */

// Prevent direct access
if (!defined('ALLOW_INCLUDE')) {
    http_response_code(403);
    die('Direct access not allowed');
}

require_once __DIR__ . '/database.php';

class CacheManager {
    private $db;
    private $cacheExpiry; // Cache expiry time in seconds (5 hours = 18000 seconds)
    
    public function __construct() {
        $this->db = Database::getInstance();
        $this->cacheExpiry = 5 * 60 * 60; // 5 hours in seconds
        
        // Initialize database table if it doesn't exist
        $this->db->initialize();
    }
    
    /**
     * Get cached data if valid
     */
    public function get($key) {
        try {
            $pdo = $this->db->getConnection();
            $stmt = $pdo->prepare("SELECT cache_data, expires_at FROM cache WHERE cache_key = ? AND expires_at > NOW()");
            $stmt->execute([$key]);
            $row = $stmt->fetch();
            
            if ($row === false) {
                return null;
            }
            
            $data = json_decode($row['cache_data'], true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                // Invalid JSON, delete cache entry
                $this->clear($key);
                return null;
            }
            
            return $data;
        } catch (PDOException $e) {
            error_log('Cache get error: ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Store data in cache
     */
    public function set($key, $data) {
        try {
            $pdo = $this->db->getConnection();
            $jsonData = json_encode($data, JSON_UNESCAPED_UNICODE);
            
            if ($jsonData === false) {
                error_log('Failed to encode cache data for key: ' . $key);
                return false;
            }
            
            $expiresAt = date('Y-m-d H:i:s', time() + $this->cacheExpiry);
            
            $stmt = $pdo->prepare("
                INSERT INTO cache (cache_key, cache_data, expires_at) 
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                    cache_data = VALUES(cache_data),
                    expires_at = VALUES(expires_at),
                    updated_at = CURRENT_TIMESTAMP
            ");
            
            $result = $stmt->execute([$key, $jsonData, $expiresAt]);
            return $result;
        } catch (PDOException $e) {
            error_log('Cache set error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Check if cache is valid (exists and not expired)
     */
    public function isValid($key) {
        try {
            $pdo = $this->db->getConnection();
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM cache WHERE cache_key = ? AND expires_at > NOW()");
            $stmt->execute([$key]);
            $row = $stmt->fetch();
            return ($row['count'] > 0);
        } catch (PDOException $e) {
            error_log('Cache isValid error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Clear specific cache entry
     */
    public function clear($key) {
        try {
            $pdo = $this->db->getConnection();
            $stmt = $pdo->prepare("DELETE FROM cache WHERE cache_key = ?");
            return $stmt->execute([$key]);
        } catch (PDOException $e) {
            error_log('Cache clear error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Clear all cache
     */
    public function clearAll() {
        try {
            $pdo = $this->db->getConnection();
            $stmt = $pdo->prepare("DELETE FROM cache");
            $stmt->execute();
            return $stmt->rowCount();
        } catch (PDOException $e) {
            error_log('Cache clearAll error: ' . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Force refresh cache (clear and mark for refresh)
     */
    public function refresh($key) {
        return $this->clear($key);
    }
    
    /**
     * Clean expired cache entries
     */
    public function cleanExpired() {
        try {
            $pdo = $this->db->getConnection();
            $stmt = $pdo->prepare("DELETE FROM cache WHERE expires_at <= NOW()");
            $stmt->execute();
            return $stmt->rowCount();
        } catch (PDOException $e) {
            error_log('Cache cleanExpired error: ' . $e->getMessage());
            return 0;
        }
    }
}

