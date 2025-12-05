<?php
/**
 * Cache Manager for Foodics API Data
 * Stores and syncs data every 5 hours
 */

// Prevent direct access
if (!defined('ALLOW_INCLUDE')) {
    http_response_code(403);
    die('Direct access not allowed');
}

class CacheManager {
    private $cacheDir;
    private $cacheDuration; // 5 hours in seconds
    
    public function __construct() {
        $this->cacheDir = __DIR__ . '/cache';
        $this->cacheDuration = 5 * 60 * 60; // 5 hours
        
        // Create cache directory if it doesn't exist
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }
    }
    
    /**
     * Check if cache is valid (not expired)
     */
    private function isCacheValid($cacheFile) {
        if (!file_exists($cacheFile)) {
            return false;
        }
        
        $cacheTime = filemtime($cacheFile);
        $currentTime = time();
        
        return ($currentTime - $cacheTime) < $this->cacheDuration;
    }
    
    /**
     * Get cached data
     */
    public function get($key) {
        $cacheFile = $this->cacheDir . '/' . $key . '.json';
        
        if (!$this->isCacheValid($cacheFile)) {
            return null;
        }
        
        $data = file_get_contents($cacheFile);
        $decoded = json_decode($data, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }
        
        return $decoded['data'] ?? null;
    }
    
    /**
     * Store data in cache
     */
    public function set($key, $data) {
        $cacheFile = $this->cacheDir . '/' . $key . '.json';
        
        $cacheData = [
            'data' => $data,
            'cached_at' => time(),
            'cached_at_readable' => date('Y-m-d H:i:s')
        ];
        
        file_put_contents($cacheFile, json_encode($cacheData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
    
    /**
     * Force sync (clear cache and mark for refresh)
     */
    public function clear($key = null) {
        if ($key) {
            $cacheFile = $this->cacheDir . '/' . $key . '.json';
            if (file_exists($cacheFile)) {
                unlink($cacheFile);
            }
        } else {
            // Clear all cache files
            $files = glob($this->cacheDir . '/*.json');
            foreach ($files as $file) {
                unlink($file);
            }
        }
    }
    
    /**
     * Get cache age in seconds
     */
    public function getCacheAge($key) {
        $cacheFile = $this->cacheDir . '/' . $key . '.json';
        
        if (!file_exists($cacheFile)) {
            return null;
        }
        
        $cacheTime = filemtime($cacheFile);
        return time() - $cacheTime;
    }
    
    /**
     * Check if sync is needed
     */
    public function needsSync($key) {
        return !$this->isCacheValid($this->cacheDir . '/' . $key . '.json');
    }
}
