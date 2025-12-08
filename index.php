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
$hiddenProductsFile = __DIR__ . '/hide/hidden_products.json';
$hiddenProducts = [];
if (file_exists($hiddenProductsFile)) {
    $hiddenProducts = json_decode(file_get_contents($hiddenProductsFile), true) ?? [];
}

// Load hidden categories list
$hiddenCategoriesFile = __DIR__ . '/hide/hidden_categories.json';
$hiddenCategories = [];
if (file_exists($hiddenCategoriesFile)) {
    $hiddenCategories = json_decode(file_get_contents($hiddenCategoriesFile), true) ?? [];
}

// Modifier prices are now read directly from product API response

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
            // If not in product data, try to get product details
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
        $singleLabel = null;
        $doubleLabel = null;
        $allPrices = [];
        $priceOptions = []; // Store price with option name for custom labels
        
        // Products that need custom labels based on option names
        $customLabelProducts = [
            '994ed07c-fd1e-4862-8b6d-53e13404a819', // Mozzarella Stick - 2 PCs / 6 PCS
            '994ed5de-93e2-405c-af7c-302e18820757',
            '994ed07d-07c8-4bb8-929a-df2b8ca86faa' // Onion Rings - 2PCS / 6PCS
        ];
        
        // Products that should show single price if all options have same price
        $singlePriceProducts = [
            '99707843-6a96-47d2-8ca1-e7164cd149e2', // Extra Burger / Capitol - all 40
            '9c4bbbb1-11c8-4b3d-bef4-fbfe3d3f7056', // Sweet Corn - all 65
        ];
        
        $needsCustomLabels = in_array($productId, $customLabelProducts);
        $shouldShowSingleIfSame = in_array($productId, $singlePriceProducts);
        
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
            
            // Skip modifiers that are extras/addons/sauces (not sizes)
            // Look for size-related keywords in modifier name
            $isSizeModifier = false;
            $sizeKeywords = ['size', 'single', 'double', 'small', 'large', 'medium', 'regular', 'big', 'options', 'quantity', 'bun', 'pcs', 'pc', 'taste'];
            foreach ($sizeKeywords as $keyword) {
                if (strpos($modifierName, $keyword) !== false) {
                    $isSizeModifier = true;
                    break;
                }
            }
            
            // Also check if modifier name suggests it's NOT a size (sauce, extra, etc.)
            // Exceptions: Products that should use modifier prices even if modifier name contains exclude keywords
            $isSaucesProduct = ($productId === '994ed5de-9567-4a80-bfb4-fedac126d132');
            $isExtraBurgerProduct = ($productId === '99707843-6a96-47d2-8ca1-e7164cd149e2');
            $shouldProcessModifier = ($isSaucesProduct || $isExtraBurgerProduct || $shouldShowSingleIfSame);
            
            $excludeKeywords = ['sauce', 'extra', 'addon', 'add-on', 'topping'];
            $isExcluded = false;
            if (!$shouldProcessModifier) {
                foreach ($excludeKeywords as $keyword) {
                    if (strpos($modifierName, $keyword) !== false && !$isSizeModifier) {
                        $isExcluded = true;
                        break;
                    }
                }
            }
            
            // If it's excluded, skip it
            if ($isExcluded) {
                continue;
            }
            
            // If no size keywords found and no exclude keywords, still process it (might be quantity-based like "Bun Quantity" or "Sauces Type")
            
            // Process options from modifier
            foreach ($options as $option) {
                // Skip deleted or inactive options
                if (!empty($option['deleted_at'])) {
                    continue;
                }
                if (isset($option['is_active']) && $option['is_active'] === false) {
                    continue;
                }
                
                $optionName = $option['name'] ?? '';
                $optionNameLower = strtolower($optionName);
                
                // Prefer explicit option price; otherwise use the lowest active/in-stock branch price
                $optionPrice = null;
                if (isset($option['price']) && $option['price'] !== null) {
                    $optionPrice = floatval($option['price']);
                } elseif (!empty($option['branches']) && is_array($option['branches'])) {
                    $branchPrices = array_filter(array_map(function($branch) {
                        $pivot = $branch['pivot'] ?? null;
                        if (!$pivot) {
                            return null;
                        }
                        $isActive = $pivot['is_active'] ?? true;
                        $inStock = $pivot['is_in_stock'] ?? true;
                        $price = $pivot['price'] ?? null;
                        if ($isActive && $inStock && $price !== null) {
                            return floatval($price);
                        }
                        return null;
                    }, $option['branches']));
                    
                    if (!empty($branchPrices)) {
                        $optionPrice = min($branchPrices); // Show the lowest available active branch price
                    }
                }
                
                if ($optionPrice !== null && $optionPrice > 0) {
                    $allPrices[] = $optionPrice;
                    
                    // For custom label products, store option name with price
                    if ($needsCustomLabels) {
                        $priceOptions[] = [
                            'price' => $optionPrice,
                            'name' => $optionName
                        ];
                    }
                    
                    // Check if this is a single or double size
                    // Priority: Check size-specific keywords first (mini/stander/standard), then generic keywords
                    $isSingleSize = false;
                    $isDoubleSize = false;
                    
                    // Check for size-specific keywords first (these take priority)
                    if (strpos($optionNameLower, 'mini') !== false) {
                        $isSingleSize = true;
                    } elseif (strpos($optionNameLower, 'stander') !== false || strpos($optionNameLower, 'standard') !== false) {
                        $isDoubleSize = true;
                    }
                    
                    // If no size-specific keyword found, check generic keywords
                    if (!$isSingleSize && !$isDoubleSize) {
                        if (strpos($optionNameLower, 'single') !== false || strpos($optionNameLower, 'small') !== false || strpos($optionNameLower, 'regular') !== false || strpos($optionNameLower, '1 ') !== false || strpos($optionNameLower, '2 ') !== false || strpos($optionNameLower, 'original') !== false || strpos($optionNameLower, 'spicy') !== false || strpos($optionNameLower, 'ranch') !== false || strpos($optionNameLower, 'buffalo') !== false) {
                            $isSingleSize = true;
                        } elseif (strpos($optionNameLower, 'double') !== false || strpos($optionNameLower, 'large') !== false || strpos($optionNameLower, 'big') !== false || strpos($optionNameLower, '3 ') !== false || strpos($optionNameLower, '6 ') !== false) {
                            $isDoubleSize = true;
                        }
                    }
                    
                    // Set prices based on size classification
                    if ($isSingleSize) {
                        if ($singlePrice === null || $optionPrice < ($singlePrice ?: 999999)) {
                            $singlePrice = $optionPrice;
                            if ($needsCustomLabels) {
                                $singleLabel = $optionName;
                            }
                        }
                    } elseif ($isDoubleSize) {
                        if ($doublePrice === null || $optionPrice > ($doublePrice ?: 0)) {
                            $doublePrice = $optionPrice;
                            if ($needsCustomLabels) {
                                $doubleLabel = $optionName;
                            }
                        }
                    }
                }
            }
        }
        
        // For custom label products, extract labels from option names
        if ($needsCustomLabels && count($priceOptions) == 2) {
            // Sort by price
            usort($priceOptions, function($a, $b) {
                return $a['price'] <=> $b['price'];
            });
            
            $singlePrice = $priceOptions[0]['price'];
            $doublePrice = $priceOptions[1]['price'];
            
            // Extract label from option name (e.g., "2 PCs Onion Ring" -> "2PCS", "6 PCs" -> "6PCS")
            // Look for pattern like "2 PCs", "6 PCS", etc.
            $singleName = $priceOptions[0]['name'];
            $doubleName = $priceOptions[1]['name'];
            
            // Extract number and PCS from option name
            if (preg_match('/(\d+)\s*(?:PC|PCS|pc|pcs)/i', $singleName, $matches)) {
                $singleLabel = $matches[1] . 'PCS';
            } else {
                $singleLabel = '2PCS'; // Default fallback
            }
            
            if (preg_match('/(\d+)\s*(?:PC|PCS|pc|pcs)/i', $doubleName, $matches)) {
                $doubleLabel = $matches[1] . 'PCS';
            } else {
                $doubleLabel = '6PCS'; // Default fallback
            }
        }
        
        // If we have exactly 2 prices and haven't identified single/double yet, treat as single/double
        if (count($allPrices) == 2 && ($singlePrice === null || $doublePrice === null) && !$needsCustomLabels) {
            sort($allPrices);
            $singlePrice = $allPrices[0];
            $doublePrice = $allPrices[1];
        }
        
        // For products that should show single price if all are same
        if ($shouldShowSingleIfSame && !empty($allPrices)) {
            $uniquePrices = array_unique($allPrices);
            if (count($uniquePrices) == 1) {
                return reset($uniquePrices); // Return single price
            }
        }
        
        // Return prices based on what we found
        if ($singlePrice !== null && $doublePrice !== null) {
            $result = ['single' => $singlePrice, 'double' => $doublePrice];
            if ($needsCustomLabels && $singleLabel && $doubleLabel) {
                $result['single_label'] = $singleLabel;
                $result['double_label'] = $doubleLabel;
            }
            return $result;
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
                            // Prefer modifier prices when available; otherwise fall back to base price.
                            $displayPrice = null;
                            $categoryId = $product['category']['id'] ?? null;
                            $forceModifierPricing = ($categoryId === '994ec5d4-234c-4e84-b423-857f900ea24c'); // Chicken Meal category
                            
                            // Use modifier pricing for targeted category; otherwise only when base price is 0 or product carries modifiers.
                            $shouldUseModifiers = $forceModifierPricing || $productPrice == 0 || $hasModifiersInData;
                            
                            if ($shouldUseModifiers) {
                                $modifierPrice = getPriceFromModifiers($api, $product);
                                if ($modifierPrice !== null) {
                                    if (is_array($modifierPrice)) {
                                        // Check if it's single/double prices
                                        if (isset($modifierPrice['single']) && isset($modifierPrice['double'])) {
                                            // If product should show a single price only, collapse to single
                                            $forceSingleOnlyProducts = [
                                                '994ed07b-d9ed-424a-8e11-a08ac9a0f9f9',
                                                '9aa0a4c1-8dc5-4984-a3b1-e480d76d19f0'
                                            ];
                                            $showSingleOnly = in_array($product['id'] ?? '', $forceSingleOnlyProducts);
                                            
                                            if ($showSingleOnly) {
                                                $displayPrice = formatPrice($modifierPrice['single']);
                                                $hasModifiers = true;
                                            } else {
                                                // Use custom labels if provided, otherwise default labels
                                                $singleLabel = $modifierPrice['single_label'] ?? '(Single)';
                                                $doubleLabel = $modifierPrice['double_label'] ?? '(Double)';
                                                
                                                // Per-product label overrides (e.g., Small/Large)
                                                $customSizeLabels = [
                                                    '994ed07c-ef29-45f6-ba41-dfb4e7bfa8e7' => ['single' => '(Small)', 'double' => '(Large)'],
                                                    '994ed07d-5ade-4eba-b4ae-eafd5f72ae5e' => ['single' => '(Small)', 'double' => '(Large)'],
                                                ];
                                                $pid = $product['id'] ?? '';
                                                if (isset($customSizeLabels[$pid])) {
                                                    $singleLabel = $customSizeLabels[$pid]['single'];
                                                    $doubleLabel = $customSizeLabels[$pid]['double'];
                                                }
                                                
                                                // Special case for Bun product
                                                if ($product['id'] === '995ac081-2b3a-4442-96b4-aae0e5bb380a') {
                                                    $singleLabel = '(1 Bun)';
                                                    $doubleLabel = '(3 Bun)';
                                                }
                                                
                                                // Format labels with parentheses if not already present
                                                if (!empty($singleLabel) && $singleLabel[0] !== '(') {
                                                    $singleLabel = '(' . $singleLabel . ')';
                                                }
                                                if (!empty($doubleLabel) && $doubleLabel[0] !== '(') {
                                                    $doubleLabel = '(' . $doubleLabel . ')';
                                                }
                                                
                                                $displayPrice = formatPrice($modifierPrice['single']) . ' <span style="font-size: 0.85em; color: #666;">' . $singleLabel . '</span> / ' . formatPrice($modifierPrice['double']) . ' <span style="font-size: 0.85em; color: #666;">' . $doubleLabel . '</span>';
                                            }
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
