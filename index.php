<?php
/**
 * Main PHP Application
 * Server-side rendered menu application
 */

// Define constant to allow includes
define('ALLOW_INCLUDE', true);

require_once 'api.php';

// Initialize API
$api = new FoodicsAPI();

// Load hidden products list
$hiddenProductsFile = __DIR__ . '/hidden_products.json';
$hiddenProducts = [];
if (file_exists($hiddenProductsFile)) {
    $hiddenProducts = json_decode(file_get_contents($hiddenProductsFile), true) ?? [];
}

// Load hidden categories list
$hiddenCategoriesFile = __DIR__ . '/hidden_categories.json';
$hiddenCategories = [];
if (file_exists($hiddenCategoriesFile)) {
    $hiddenCategories = json_decode(file_get_contents($hiddenCategoriesFile), true) ?? [];
}

// Modifier prices are now read directly from product API response

// Get current page and category from URL
$categoryId = isset($_GET['category']) ? $_GET['category'] : null;
$forceSync = isset($_GET['sync']) && $_GET['sync'] === '1'; // Manual sync trigger
$currentPage = $categoryId ? 'products' : 'categories';

// Initialize variables
$categories = [];
$products = [];
$currentCategory = null;
$error = null;

try {
    if ($currentPage === 'categories') {
        // Load categories (from cache if available, or API if cache expired)
        $categories = $api->getCategories($forceSync);
        
        // Filter out hidden categories
        $categories = array_filter($categories, function($cat) use ($hiddenCategories) {
            return !in_array($cat['id'], $hiddenCategories);
        });
        $categories = array_values($categories); // Re-index array
    } else {
        // Load category details and products
        $categories = $api->getCategories($forceSync);
        $currentCategory = array_filter($categories, function($cat) use ($categoryId) {
            return $cat['id'] === $categoryId;
        });
        $currentCategory = !empty($currentCategory) ? reset($currentCategory) : null;
        
        if ($currentCategory) {
            $products = $api->getProductsByCategory($categoryId, $forceSync);
            
            // Filter out hidden products, inactive products, and deleted products
            $products = array_filter($products, function($product) use ($hiddenProducts) {
                // Check if product is hidden
                if (in_array($product['id'], $hiddenProducts)) {
                    return false;
                }
                // Check if product is active (is_active must be true)
                $isActive = $product['is_active'] ?? true; // Default to true if not set
                if ($isActive !== true) {
                    return false;
                }
                // Check if product is deleted (deleted_at must be null)
                $deletedAt = $product['deleted_at'] ?? null;
                if ($deletedAt !== null) {
                    return false;
                }
                return true;
            });
            $products = array_values($products); // Re-index array
            
            // Check for modifiers in products (optional, can be slow)
            // This is done in the background in the JS version, but we'll do it here
            // You might want to optimize this or make it optional
        } else {
            $error = 'Category not found';
        }
    }
} catch (Exception $e) {
    $error = $e->getMessage();
}

/**
 * Helper function to escape HTML
 */
function h($string) {
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}

/**
 * Helper function to format price
 */
function formatPrice($price) {
    if (is_numeric($price)) {
        if ($price == 0) {
            return 'Free';
        }
        return number_format($price, 2) . ' EGP';
    }
    return $price ?? '';
}

/**
 * Get price from product modifiers
 * Returns size prices (single/double) and excludes extra/addon prices
 * Reads modifiers directly from product data (from API response)
 */
function getPriceFromModifiers($api, $product) {
    try {
        $productId = $product['id'] ?? null;
        if (!$productId) {
            return null;
        }
        
        // Check if modifiers exist in product data (from API response)
        $modifiers = $product['modifiers'] ?? [];
        if (empty($modifiers)) {
            // If not in product data, try to get product details from cache/API
            try {
                $productDetails = $api->getProductDetails($productId);
                if ($productDetails) {
                    $modifiers = $productDetails['modifiers'] ?? [];
                }
            } catch (Exception $e) {
                // If we can't get product details, return null
                return null;
            }
        }
        
        if (empty($modifiers)) {
            return null;
        }
        
        $singlePrice = null;
        $doublePrice = null;
        $allPrices = [];
        
        // Process all modifiers from product data
        foreach ($modifiers as $modifier) {
            $modifierId = $modifier['id'] ?? null;
            if (!$modifierId) {
                continue;
            }
            
            $modifierName = strtolower($modifier['name'] ?? '');
            $options = $modifier['options'] ?? [];
            
            if (empty($options)) {
                continue;
            }
            
            // For products with price 0, process all modifiers (they might be the source of pricing)
            // For products with price > 0, only process size-related modifiers (exclude sauces/extras)
            $productPrice = $product['price'] ?? 0;
            $shouldProcessModifier = true;
            
            if ($productPrice > 0) {
                // Product has a base price, only process size modifiers
                $isSizeModifier = false;
                $sizeKeywords = ['size', 'single', 'double', 'small', 'large', 'medium', 'regular', 'big', 'options', 'quantity', 'bun', 'pcs', 'pc', 'taste'];
                foreach ($sizeKeywords as $keyword) {
                    if (strpos($modifierName, $keyword) !== false) {
                        $isSizeModifier = true;
                        break;
                    }
                }
                
                // Exclude modifiers that are extras/addons/sauces (not sizes) when product has base price
                $excludeKeywords = ['sauce', 'extra', 'addon', 'add-on', 'topping'];
                $isExcluded = false;
                foreach ($excludeKeywords as $keyword) {
                    if (strpos($modifierName, $keyword) !== false && !$isSizeModifier) {
                        $isExcluded = true;
                        break;
                    }
                }
                
                if ($isExcluded) {
                    $shouldProcessModifier = false;
                }
            }
            // If product price is 0, process all modifiers (they are likely the source of pricing)
            
            if (!$shouldProcessModifier) {
                continue;
            }
            
            // Process options from modifier
            foreach ($options as $option) {
                // Skip deleted options
                if (!empty($option['deleted_at'])) {
                    continue;
                }
                
                $optionName = strtolower($option['name'] ?? '');
                $optionPrice = isset($option['price']) ? floatval($option['price']) : 0;
                
                if ($optionPrice > 0) {
                    $allPrices[] = $optionPrice;
                    
                    // Check if this is a single or double size
                    if (strpos($optionName, 'single') !== false || strpos($optionName, 'small') !== false || strpos($optionName, 'regular') !== false || strpos($optionName, '1 ') !== false || strpos($optionName, '2 ') !== false || strpos($optionName, 'original') !== false || strpos($optionName, 'spicy') !== false || strpos($optionName, 'ranch') !== false || strpos($optionName, 'buffalo') !== false) {
                        if ($singlePrice === null || $optionPrice < ($singlePrice ?: 999999)) {
                            $singlePrice = $optionPrice;
                        }
                    } elseif (strpos($optionName, 'double') !== false || strpos($optionName, 'large') !== false || strpos($optionName, 'big') !== false || strpos($optionName, '3 ') !== false || strpos($optionName, '6 ') !== false) {
                        if ($doublePrice === null || $optionPrice > ($doublePrice ?: 0)) {
                            $doublePrice = $optionPrice;
                        }
                    }
                }
            }
        }
        
        // If we have exactly 2 prices and haven't identified single/double yet, treat as single/double
        if (count($allPrices) == 2 && ($singlePrice === null || $doublePrice === null)) {
            sort($allPrices);
            $singlePrice = $allPrices[0];
            $doublePrice = $allPrices[1];
        }
        
        // Return prices based on what we found
        if ($singlePrice !== null && $doublePrice !== null) {
            return ['single' => $singlePrice, 'double' => $doublePrice];
        } elseif ($singlePrice !== null) {
            return $singlePrice;
        } elseif ($doublePrice !== null) {
            return $doublePrice;
        } elseif (!empty($allPrices)) {
            $minPrice = min($allPrices);
            $maxPrice = max($allPrices);
            if ($minPrice == $maxPrice) {
                return $minPrice;
            } else {
                return ['min' => $minPrice, 'max' => $maxPrice];
            }
        }
    } catch (Exception $e) {
        error_log('Error fetching modifier prices for product ' . ($product['id'] ?? 'unknown') . ': ' . $e->getMessage());
    }
    
    return null;
}

/**
 * Generate placeholder image SVG
 */
function getPlaceholderImage($text) {
    $encoded = urlencode($text);
    return "data:image/svg+xml,%3Csvg xmlns=%27http://www.w3.org/2000/svg%27 width=%27200%27 height=%27200%27%3E%3Crect fill=%27%23ddd%27 width=%27200%27 height=%27200%27/%3E%3Ctext fill=%27%23999%27 font-family=%27sans-serif%27 font-size=%2714%27 dy=%2710.5%27 font-weight=%27bold%27 x=%2750%25%27 y=%2750%25%27 text-anchor=%27middle%27%3E{$encoded}%3C/text%3E%3C/svg%3E";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hunger Station - Menu</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <div class="container">
        <!-- Navigation -->
        <nav class="navbar">
            <div class="nav-content">
                <div style="display: flex; align-items: center; gap: 15px;">
                    <a href="index.php" style="display: flex; align-items: center; text-decoration: none;">
                        <img src="assets/logo.png" alt="Hunger Station" class="logo-img">
                    </a>
                    <h1 class="logo" >HungerStation Menu</h1>
                </div>
                <div style="display: flex; gap: 10px; align-items: center;">
                    <?php if ($currentPage === 'products'): ?>
                        <a href="index.php" class="back-btn">← Back to Categories</a>
                    <?php endif; ?>
                    <?php if (isset($_GET['sync']) && $_GET['sync'] === '1'): ?>
                        <span style="color: green; font-size: 0.9em;">✓ Data synced</span>
                    <?php endif; ?>
                </div>
            </div>
        </nav>

        <?php if ($currentPage === 'categories'): ?>
            <!-- Categories Page -->
            <div class="page active">
                <div class="page-header">
                    <h2>Categories</h2>
                    <p>Select a category to view products</p>
                </div>
                <div class="cards-container">
                    <?php if ($error): ?>
                        <div class="error">Error loading categories: <?php echo h($error); ?></div>
                    <?php elseif (empty($categories)): ?>
                        <div class="error">No categories found</div>
                    <?php else: ?>
                        <?php foreach ($categories as $category): ?>
                            <div class="card category-card">
                                <a href="index.php?category=<?php echo h($category['id']); ?>" style="text-decoration: none; color: inherit; display: block;">
                                    <div class="card-image">
                                        <?php if (!empty($category['image'])): ?>
                                            <img src="<?php echo h($category['image']); ?>" 
                                                 alt="<?php echo h($category['name'] ?? 'Category'); ?>"
                                                 onerror="this.src='<?php echo getPlaceholderImage($category['name'] ?? 'Category'); ?>'">
                                        <?php else: ?>
                                            <div class="placeholder-image">
                                                <?php echo h(strtoupper(substr($category['name'] ?? 'C', 0, 1))); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="card-content">
                                        <h3 class="card-title"><?php echo h($category['name'] ?? 'Unnamed Category'); ?></h3>
                                        <?php if (!empty($category['name_localized'])): ?>
                                            <p class="card-description"><?php echo h($category['name_localized']); ?></p>
                                        <?php endif; ?>
                                    </div>
                                </a>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

        <?php else: ?>
            <!-- Products Page -->
            <div class="page active">
                <div class="page-header">
                    <h2><?php echo h($currentCategory['name'] ?? 'Products'); ?></h2>
                    <?php if (!empty($currentCategory['name_localized'])): ?>
                        <p><?php echo h($currentCategory['name_localized']); ?></p>
                    <?php endif; ?>
                </div>
                <div class="cards-container">
                    <?php if ($error): ?>
                        <div class="error">Error loading products: <?php echo h($error); ?></div>
                    <?php elseif (empty($products)): ?>
                        <div class="error">No products found in this category</div>
                    <?php else: ?>
                        <?php foreach ($products as $product): ?>
                            <?php
                            $displayName = $product['name'] ?? 'Unnamed Product';
                            $displayDescription = $product['description'] ?? $product['description_localized'] ?? '';
                            $productPrice = $product['price'] ?? 0;
                            
                            // Check if product has modifiers in initial data
                            $hasModifiers = !empty($product['modifiers']) && count($product['modifiers']) > 0;
                            
                            // Check if product has modifiers (from API response)
                            $hasModifiersInData = !empty($product['modifiers']) && count($product['modifiers']) > 0;
                            
                            // Only use modifier prices if:
                            // 1. Product price is 0 (free), OR
                            // 2. Product has modifiers in the API response
                            // Otherwise, use the product's base price
                            $displayPrice = null;
                            if ($productPrice == 0 || $hasModifiersInData) {
                                // Product is free or has modifiers - check modifiers from product data
                                $modifierPrice = getPriceFromModifiers($api, $product);
                                if ($modifierPrice !== null) {
                                    if (is_array($modifierPrice)) {
                                        // Check if it's single/double prices
                                        if (isset($modifierPrice['single']) && isset($modifierPrice['double'])) {
                                            // Custom labels for Bun product
                                            $singleLabel = '(Single)';
                                            $doubleLabel = '(Double)';
                                            if ($product['id'] === '995ac081-2b3a-4442-96b4-aae0e5bb380a') {
                                                $singleLabel = '(1 Bun)';
                                                $doubleLabel = '(3 Bun)';
                                            }
                                            $displayPrice = formatPrice($modifierPrice['single']) . ' <span style="font-size: 0.85em; color: #666;">' . $singleLabel . '</span> / ' . formatPrice($modifierPrice['double']) . ' <span style="font-size: 0.85em; color: #666;">' . $doubleLabel . '</span>';
                                        } elseif (isset($modifierPrice['min']) && isset($modifierPrice['max'])) {
                                            // Price range
                                            $displayPrice = formatPrice($modifierPrice['min']) . ' - ' . formatPrice($modifierPrice['max']);
                                        } else {
                                            // Fallback for other array formats
                                            $displayPrice = formatPrice($modifierPrice['min'] ?? $modifierPrice['single'] ?? 0);
                                        }
                                    } else {
                                        // Single price value
                                        $displayPrice = formatPrice($modifierPrice);
                                    }
                                    // Update hasModifiers flag if we found modifier prices
                                    $hasModifiers = true;
                                }
                            }
                            
                            // If no modifier price found (or product has base price), use product price
                            if ($displayPrice === null) {
                                $displayPrice = formatPrice($productPrice);
                            }
                            ?>
                            <div class="card product-card">
                                <div class="card-image">
                                    <?php if (!empty($product['image'])): ?>
                                        <img src="<?php echo h($product['image']); ?>" 
                                             alt="<?php echo h($displayName); ?>"
                                             onerror="this.src='<?php echo getPlaceholderImage($displayName); ?>'">
                                    <?php else: ?>
                                        <div class="placeholder-image">
                                            <?php echo h(strtoupper(substr($displayName, 0, 1))); ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($hasModifiers): ?>
                                        
                                    <?php endif; ?>
                                </div>
                                <div class="card-content">
                                    <h3 class="card-title"><?php echo h($displayName); ?></h3>
                                    <?php if ($displayDescription): ?>
                                        <p class="card-description"><?php echo h($displayDescription); ?></p>
                                    <?php endif; ?>
                                    <?php if ($displayPrice): ?>
                                        <p class="card-price"><?php echo $displayPrice; ?></p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
        
        <!-- Footer -->
        <footer style="text-align: center; padding: 30px 20px; margin-top: 50px; color: white; opacity: 0.9;">
            <p style="margin: 0; font-size: 14px;">
                &copy; <?php echo date('Y'); ?> Designed by <strong>Omar Khaled</strong>
            </p>
        </footer>
    </div>
</body>
</html>
