<?php
/**
 * Manual Sync Endpoint
 * Forces refresh of all cached data from API
 * Access: sync.php or index.php?sync=1
 */

// Prevent direct access
if (!defined('ALLOW_INCLUDE')) {
    define('ALLOW_INCLUDE', true);
}

require_once 'api.php';
require_once 'cache.php';

header('Content-Type: application/json');

try {
    $api = new FoodicsAPI();
    $cache = new CacheManager();
    
    $results = [
        'success' => true,
        'synced_at' => date('Y-m-d H:i:s'),
        'items' => []
    ];
    
    // Sync categories
    try {
        $categories = $api->getCategories(true); // Force refresh
        $results['items'][] = [
            'type' => 'categories',
            'count' => count($categories),
            'status' => 'success'
        ];
    } catch (Exception $e) {
        $results['items'][] = [
            'type' => 'categories',
            'status' => 'error',
            'message' => $e->getMessage()
        ];
    }
    
    // Note: Products are cached per category, so they will be synced automatically when accessed
    // You can also clear all product caches if needed:
    // $cache->clear(); // This clears all cache
    
    echo json_encode($results, JSON_PRETTY_PRINT);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_PRETTY_PRINT);
}
