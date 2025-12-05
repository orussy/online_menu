<?php
/**
 * Cache Sync Endpoint
 * Allows manual cache refresh/sync
 */

// Prevent direct access (only allow from same origin or with secret key)
define('ALLOW_INCLUDE', true);

require_once 'api.php';
require_once 'cache.php';

// Check if this is a sync request
$action = $_GET['action'] ?? 'status';
$secretKey = $_GET['secret'] ?? '';

// Simple security: require a secret key to sync (you can set this in config.php)
$config = require __DIR__ . '/config.php';
$syncKey = $config['sync_key'] ?? 'sync123'; // Default key, change in config.php

// For status, allow without key. For sync/clear, require key
if (in_array($action, ['sync', 'clear', 'refresh']) && $secretKey !== $syncKey) {
    http_response_code(403);
    die('Unauthorized: Invalid sync key');
}

$api = new FoodicsAPI();
$cache = new CacheManager();

header('Content-Type: application/json');

try {
    switch ($action) {
        case 'sync':
            // Clear all cache and force refresh
            $deleted = $cache->clearAll();
            
            // Pre-fetch common data to warm cache
            $api->getCategories();
            
            echo json_encode([
                'success' => true,
                'message' => 'Cache cleared and synced',
                'deleted_files' => $deleted
            ]);
            break;
            
        case 'clear':
            // Clear all cache
            $deleted = $cache->clearAll();
            echo json_encode([
                'success' => true,
                'message' => 'Cache cleared',
                'deleted_files' => $deleted
            ]);
            break;
            
        case 'refresh':
            // Refresh specific cache key
            $cacheKey = $_GET['cache_key'] ?? null;
            if ($cacheKey) {
                $cache->refresh($cacheKey);
                echo json_encode([
                    'success' => true,
                    'message' => "Cache refreshed for key: $cacheKey"
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => 'cache_key parameter required for refresh'
                ]);
            }
            break;
            
        case 'status':
        default:
            // Get cache status
            $cacheDir = __DIR__ . '/cache';
            $files = glob($cacheDir . '/*.json');
            $cacheInfo = [];
            
            foreach ($files as $file) {
                $key = basename($file, '.json');
                $fileTime = filemtime($file);
                $age = time() - $fileTime;
                $ageHours = round($age / 3600, 2);
                
                $cacheInfo[] = [
                    'key' => $key,
                    'age_hours' => $ageHours,
                    'expired' => $age > (5 * 3600),
                    'size' => filesize($file)
                ];
            }
            
            echo json_encode([
                'success' => true,
                'cache_expiry_hours' => 5,
                'total_files' => count($files),
                'files' => $cacheInfo
            ]);
            break;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>

