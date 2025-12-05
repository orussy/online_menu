<?php
/**
 * Cache Management System
 * Handles caching of API responses to reduce API calls and improve performance
 */

// Prevent direct access
if (!defined('ALLOW_INCLUDE')) {
    http_response_code(403);
    die('Direct access not allowed');
}

class CacheManager {
    private $cacheDir;
    private $cacheExpiry; // Cache expiry time in seconds (5 hours = 18000 seconds)
    
    public function __construct() {
        $this->cacheDir = __DIR__ . '/cache';
        $this->cacheExpiry = 5 * 60 * 60; // 5 hours in seconds
        
        // Create cache directory if it doesn't exist
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }
    }
    
    /**
     * Get cache file path for a given key
     */
    private function getCacheFilePath($key) {
        $safeKey = preg_replace('/[^a-zA-Z0-9_-]/', '_', $key);
        return $this->cacheDir . '/' . $safeKey . '.json';
    }
    
    /**
     * Get cached data if valid
     */
    public function get($key) {
        $cacheFile = $this->getCacheFilePath($key);
        
        if (!file_exists($cacheFile)) {
            return null;
        }
        
        // Check if cache is expired
        $fileTime = filemtime($cacheFile);
        $currentTime = time();
        
        if (($currentTime - $fileTime) > $this->cacheExpiry) {
            // Cache expired, delete it
            @unlink($cacheFile);
            return null;
        }
        
        // Read and return cached data
        $content = file_get_contents($cacheFile);
        if ($content === false) {
            return null;
        }
        
        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            // Invalid JSON, delete cache file
            @unlink($cacheFile);
            return null;
        }
        
        return $data;
    }
    
    /**
     * Store data in cache
     */
    public function set($key, $data) {
        $cacheFile = $this->getCacheFilePath($key);
        
        $jsonData = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($jsonData === false) {
            error_log('Failed to encode cache data for key: ' . $key);
            return false;
        }
        
        $result = file_put_contents($cacheFile, $jsonData, LOCK_EX);
        if ($result === false) {
            error_log('Failed to write cache file: ' . $cacheFile);
            return false;
        }
        
        return true;
    }
    
    /**
     * Check if cache is valid (exists and not expired)
     */
    public function isValid($key) {
        $cacheFile = $this->getCacheFilePath($key);
        
        if (!file_exists($cacheFile)) {
            return false;
        }
        
        $fileTime = filemtime($cacheFile);
        $currentTime = time();
        
        return (($currentTime - $fileTime) <= $this->cacheExpiry);
    }
    
    /**
     * Clear specific cache entry
     */
    public function clear($key) {
        $cacheFile = $this->getCacheFilePath($key);
        if (file_exists($cacheFile)) {
            return @unlink($cacheFile);
        }
        return true;
    }
    
    /**
     * Clear all cache
     */
    public function clearAll() {
        $files = glob($this->cacheDir . '/*.json');
        $deleted = 0;
        foreach ($files as $file) {
            if (is_file($file)) {
                if (@unlink($file)) {
                    $deleted++;
                }
            }
        }
        return $deleted;
    }
    
    /**
     * Force refresh cache (clear and mark for refresh)
     */
    public function refresh($key) {
        return $this->clear($key);
    }
}

