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

// Get current page and category from URL
$categoryId = isset($_GET['category']) ? $_GET['category'] : null;
$currentPage = $categoryId ? 'products' : 'categories';

// Initialize variables
$categories = [];
$products = [];
$currentCategory = null;
$error = null;

try {
    if ($currentPage === 'categories') {
        // Load categories
        $categories = $api->getCategories();
        
        // Filter out hidden categories
        $categories = array_filter($categories, function($cat) use ($hiddenCategories) {
            return !in_array($cat['id'], $hiddenCategories);
        });
        $categories = array_values($categories); // Re-index array
    } else {
        // Load category details and products
        $categories = $api->getCategories();
        $currentCategory = array_filter($categories, function($cat) use ($categoryId) {
            return $cat['id'] === $categoryId;
        });
        $currentCategory = !empty($currentCategory) ? reset($currentCategory) : null;
        
        if ($currentCategory) {
            $products = $api->getProductsByCategory($categoryId);
            
            // Filter out hidden products and inactive products
            $products = array_filter($products, function($product) use ($hiddenProducts) {
                // Check if product is hidden
                if (in_array($product['id'], $hiddenProducts)) {
                    return false;
                }
                // Check if product is active (is_active must be true)
                $isActive = $product['is_active'] ?? true; // Default to true if not set
                return $isActive === true;
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
 * Returns the minimum price from modifier options, or null if no prices found
 */
function getPriceFromModifiers($api, $product) {
    try {
        // Get product details to access modifier IDs
        $productDetails = $api->getProductDetails($product['id']);
        if (!$productDetails) {
            return null;
        }
        
        // Check if modifiers exist in product details
        $modifiers = $productDetails['modifiers'] ?? [];
        if (empty($modifiers)) {
            return null;
        }
        
        $minPrice = null;
        $maxPrice = null;
        
        // Check each modifier for prices
        foreach ($modifiers as $modifier) {
            $modifierId = $modifier['id'] ?? null;
            if (!$modifierId) {
                continue;
            }
            
            try {
                $modifierDetails = $api->getModifierDetails($modifierId);
                if (!$modifierDetails || empty($modifierDetails['options'])) {
                    continue;
                }
                
                foreach ($modifierDetails['options'] as $option) {
                    // Skip deleted options
                    if (!empty($option['deleted_at'])) {
                        continue;
                    }
                    
                    $optionPrice = isset($option['price']) ? floatval($option['price']) : 0;
                    if ($optionPrice > 0) {
                        if ($minPrice === null || $optionPrice < $minPrice) {
                            $minPrice = $optionPrice;
                        }
                        if ($maxPrice === null || $optionPrice > $maxPrice) {
                            $maxPrice = $optionPrice;
                        }
                    }
                }
            } catch (Exception $e) {
                // Continue to next modifier if this one fails
                error_log('Error fetching modifier ' . $modifierId . ': ' . $e->getMessage());
                continue;
            }
        }
        
        // Return price range or single price
        if ($minPrice !== null) {
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
                            
                            // If product is free (price = 0), always check for modifiers
                            // Products with price 0 might have prices in modifier options
                            $displayPrice = null;
                            if ($productPrice == 0) {
                                $modifierPrice = getPriceFromModifiers($api, $product);
                                if ($modifierPrice !== null) {
                                    if (is_array($modifierPrice)) {
                                        $displayPrice = formatPrice($modifierPrice['min']) . ' - ' . formatPrice($modifierPrice['max']);
                                    } else {
                                        $displayPrice = formatPrice($modifierPrice);
                                    }
                                    // Update hasModifiers flag if we found modifier prices
                                    $hasModifiers = true;
                                }
                            }
                            
                            // If no modifier price found, use product price
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
                                        <p class="card-price"><?php echo h($displayPrice); ?></p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
