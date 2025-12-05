<?php
/**
 * Foodics API PHP Class
 * Server-side API client for Foodics API
 */

// Prevent direct access
if (!defined('ALLOW_INCLUDE')) {
    http_response_code(403);
    die('Direct access not allowed');
}

class FoodicsAPI {
    private $baseURL;
    private $token;
    private $cache;

    public function __construct() {
        // Load configuration
        $config = require __DIR__ . '/config.php';
        
        $this->baseURL = $config['api_base_url'];
        $this->token = $config['token'];
        
        // Initialize cache manager
        require_once __DIR__ . '/cache.php';
        $this->cache = new CacheManager();
        
        // Validate token
        if (empty($this->token) || $this->token === 'YOUR_TOKEN_HERE') {
            throw new Exception('API token not configured. Please update config.php with your valid Foodics API token.');
        }
    }

    /**
     * Make API request directly to Foodics API
     */
    private function fetch($endpoint) {
        // Build the full API URL
        $apiUrl = $this->baseURL . $endpoint;
        
        // Initialize cURL
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        
        // Set HTTP headers with proper Authorization header
        // Note: Don't trim token as it might contain valid characters at the end
        $headers = [
            'Authorization: Bearer ' . $this->token,
            'Content-Type: application/json',
            'Accept: application/json'
        ];
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        
        // Execute request
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            throw new Exception('cURL Error: ' . $error);
        }
        
        if ($httpCode !== 200) {
            $errorData = json_decode($response, true);
            $errorMessage = "API Error: HTTP $httpCode";
            
            // Add more details for 401 errors
            if ($httpCode === 401) {
                $errorMessage .= " - Unauthorized";
                if (isset($errorData['message'])) {
                    $errorMessage .= ": " . $errorData['message'];
                } elseif (isset($errorData['error'])) {
                    $errorMessage .= ": " . $errorData['error'];
                }
                $errorMessage .= " (Check if token is valid and not expired)";
            } elseif (isset($errorData['error'])) {
                $errorMessage .= ": " . $errorData['error'];
            } elseif (isset($errorData['message'])) {
                $errorMessage .= ": " . $errorData['message'];
            }
            
            throw new Exception($errorMessage);
        }
        
        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid JSON response');
        }
        
        return $data;
    }

    /**
     * Get all categories (handles pagination)
     */
    public function getCategories($forceRefresh = false) {
        try {
            // Check cache first
            if (!$forceRefresh) {
                $cached = $this->cache->get('categories');
                if ($cached !== null) {
                    return $cached;
                }
            }
            
            // Cache miss or force refresh - fetch from API
            $allCategories = [];
            $currentPage = 1;
            $hasMorePages = true;
            
            while ($hasMorePages) {
                $data = $this->fetch("categories?page=$currentPage");
                $categories = $data['data'] ?? [];
                
                // Filter out deleted categories and inactive categories
                $activeCategories = array_filter($categories, function($cat) {
                    // Check if category is deleted
                    if (($cat['deleted_at'] ?? null) !== null) {
                        return false;
                    }
                    // Check if category is active (is_active must be true)
                    $isActive = $cat['is_active'] ?? true; // Default to true if not set
                    return $isActive === true;
                });
                
                $allCategories = array_merge($allCategories, $activeCategories);
                
                // Check if there are more pages
                if (isset($data['links']['next']) && !empty($data['links']['next'])) {
                    $currentPage++;
                } else {
                    $hasMorePages = false;
                }
            }
            
            // Store in cache
            $this->cache->set('categories', $allCategories);
            
            return $allCategories;
        } catch (Exception $e) {
            // If API fails, try to return cached data even if expired
            $cached = $this->cache->get('categories');
            if ($cached !== null) {
                error_log('API error, using cached categories: ' . $e->getMessage());
                return $cached;
            }
            
            error_log('Error fetching categories: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get products by category ID
     */
    public function getProductsByCategory($categoryId, $forceRefresh = false) {
        try {
            $cacheKey = 'products_category_' . $categoryId;
            
            // Check cache first
            if (!$forceRefresh) {
                $cached = $this->cache->get($cacheKey);
                if ($cached !== null) {
                    return $cached;
                }
            }
            
            // Cache miss or force refresh - fetch from API
            $response = $this->fetch("categories/$categoryId");
            $products = $response['data']['products'] ?? [];
            
            // Filter out deleted products
            $activeProducts = array_filter($products, function($product) {
                return ($product['deleted_at'] ?? null) === null;
            });
            
            $activeProducts = array_values($activeProducts);
            
            // Store in cache
            $this->cache->set($cacheKey, $activeProducts);
            
            return $activeProducts;
        } catch (Exception $e) {
            // If API fails, try to return cached data even if expired
            $cacheKey = 'products_category_' . $categoryId;
            $cached = $this->cache->get($cacheKey);
            if ($cached !== null) {
                error_log('API error, using cached products for category ' . $categoryId . ': ' . $e->getMessage());
                return $cached;
            }
            
            error_log('Error fetching products: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get all products (handles pagination)
     */
    public function getAllProducts() {
        try {
            $allProducts = [];
            $currentPage = 1;
            $hasMorePages = true;
            
            while ($hasMorePages) {
                $data = $this->fetch("products?page=$currentPage");
                $products = $data['data'] ?? [];
                
                // Filter out deleted products and inactive products
                $activeProducts = array_filter($products, function($product) {
                    // Check if product is deleted
                    if (($product['deleted_at'] ?? null) !== null) {
                        return false;
                    }
                    // Check if product is active (is_active must be true)
                    $isActive = $product['is_active'] ?? true; // Default to true if not set
                    return $isActive === true;
                });
                
                $allProducts = array_merge($allProducts, $activeProducts);
                
                // Check if there are more pages
                if (isset($data['links']['next']) && !empty($data['links']['next'])) {
                    $currentPage++;
                } else {
                    $hasMorePages = false;
                }
            }
            
            return $allProducts;
        } catch (Exception $e) {
            error_log('Error fetching products: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get product details including modifiers
     */
    public function getProductDetails($productId, $forceRefresh = false) {
        try {
            $cacheKey = 'product_' . $productId;
            
            // Check cache first
            if (!$forceRefresh) {
                $cached = $this->cache->get($cacheKey);
                if ($cached !== null) {
                    return $cached;
                }
            }
            
            // Cache miss or force refresh - fetch from API
            $data = $this->fetch("products/$productId");
            $productData = $data['data'] ?? null;
            
            if ($productData) {
                // Store in cache
                $this->cache->set($cacheKey, $productData);
            }
            
            return $productData;
        } catch (Exception $e) {
            // If API fails, try to return cached data even if expired
            $cacheKey = 'product_' . $productId;
            $cached = $this->cache->get($cacheKey);
            if ($cached !== null) {
                error_log('API error, using cached product ' . $productId . ': ' . $e->getMessage());
                return $cached;
            }
            
            error_log('Error fetching product details: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get all modifiers (handles pagination)
     */
    public function getAllModifiers() {
        try {
            $allModifiers = [];
            $currentPage = 1;
            $hasMorePages = true;
            
            while ($hasMorePages) {
                $data = $this->fetch("modifiers?page=$currentPage");
                $modifiers = $data['data'] ?? [];
                
                // Filter out deleted modifiers and inactive modifiers
                $activeModifiers = array_filter($modifiers, function($modifier) {
                    // Check if modifier is deleted
                    if (($modifier['deleted_at'] ?? null) !== null) {
                        return false;
                    }
                    // Check if modifier is active (is_active must be true)
                    $isActive = $modifier['is_active'] ?? true; // Default to true if not set
                    return $isActive === true;
                });
                
                $allModifiers = array_merge($allModifiers, $activeModifiers);
                
                // Check if there are more pages
                if (isset($data['links']['next']) && !empty($data['links']['next'])) {
                    $currentPage++;
                } else {
                    $hasMorePages = false;
                }
            }
            
            return $allModifiers;
        } catch (Exception $e) {
            error_log('Error fetching modifiers: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get modifier details including options
     */
    public function getModifierDetails($modifierId, $forceRefresh = false) {
        try {
            $cacheKey = 'modifier_' . $modifierId;
            
            // Check cache first
            if (!$forceRefresh) {
                $cached = $this->cache->get($cacheKey);
                if ($cached !== null) {
                    return $cached;
                }
            }
            
            // Cache miss or force refresh - fetch from API
            $data = $this->fetch("modifiers/$modifierId");
            if (isset($data['data'])) {
                // Filter out deleted options and inactive options
                if (isset($data['data']['options'])) {
                    $data['data']['options'] = array_values(array_filter(
                        $data['data']['options'],
                        function($option) {
                            // Check if option is deleted
                            if (($option['deleted_at'] ?? null) !== null) {
                                return false;
                            }
                            // Check if option is active (is_active must be true)
                            $isActive = $option['is_active'] ?? true; // Default to true if not set
                            return $isActive === true;
                        }
                    ));
                }
                
                $modifierData = $data['data'];
                
                // Store in cache
                $this->cache->set($cacheKey, $modifierData);
                
                return $modifierData;
            }
            return null;
        } catch (Exception $e) {
            // If API fails, try to return cached data even if expired
            $cacheKey = 'modifier_' . $modifierId;
            $cached = $this->cache->get($cacheKey);
            if ($cached !== null) {
                error_log('API error, using cached modifier ' . $modifierId . ': ' . $e->getMessage());
                return $cached;
            }
            
            error_log('Error fetching modifier details: ' . $e->getMessage());
            throw $e;
        }
    }
}
?>
