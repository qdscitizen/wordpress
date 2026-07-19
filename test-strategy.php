<?php
/**
 * Test script for strategy-based generation.
 * Run this after logging into WP admin: http://localhost/wordpress/wp-admin/test-strategy.php
 * Or run via WP CLI.
 */

// Bootstrap WordPress
define('WP_USE_THEMES', false);
require_once dirname(__FILE__) . '/wp-load.php';

// Ensure we have admin access
if (!current_user_can('manage_options')) {
    die('Admin access required');
}

// Check if we're in test mode
$strategy = $_GET['strategy'] ?? 'all';
$product_id = intval($_GET['product_id'] ?? 0);

if (!$product_id) {
    // Show test UI
    ?>
    <h1>Strategy Test</h1>
    <p>Select a product and strategy to test:</p>
    <form method="get">
        Product ID: <input type="number" name="product_id" value="191" min="1"><br>
        Strategy: 
        <select name="strategy">
            <option value="all">All</option>
            <option value="title">SEO Title Only</option>
            <option value="description">Product Description Only</option>
            <option value="seo">SEO Meta Tags Only</option>
        </select>
        <button type="submit">Test</button>
    </form>
    <?php
    // List available products
    $products = wc_get_products(['limit' => 10, 'status' => 'publish']);
    echo "<h3>Available products:</h3><ul>";
    foreach ($products as $p) {
        echo "<li>#{$p->get_id()} - {$p->get_name()}</li>";
    }
    echo "</ul>";
    exit;
}

// Build a simulated request
$product = wc_get_product($product_id);
if (!$product) {
    die("Product #{$product_id} not found");
}

echo "<h2>Testing strategy: <strong>{$strategy}</strong></h2>";
echo "<p>Product: #{$product_id} - {$product->get_name()}</p>";
echo "<hr>";

// Build prompt
$product_data = WooAI_Desc_Product_Handler::format_product($product);
echo "<h3>Product Data:</h3>";
echo "<pre>" . print_r($product_data, true) . "</pre>";

$prompt = WooAI_Desc_Settings::build_prompt($product_data, [
    'strategy' => $strategy,
    'language' => 'en',
    'tone' => 'professional',
    'keywords' => [],
    'writing_angle' => '',
]);

echo "<h3>Generated Prompt:</h3>";
echo "<pre>" . htmlspecialchars($prompt) . "</pre>";

// Extract the output format section to verify
preg_match('/Output format.*?\n\{.*?\}\n/s', $prompt, $m);
echo "<h3>Output Format Section:</h3>";
echo "<pre>" . htmlspecialchars($m[0] ?? 'NOT FOUND') . "</pre>";

// Verify filter_by_strategy
$test_data = [
    'title' => 'Test Title',
    'description' => 'Test Description',
    'short_description' => 'Test Short',
    'meta_title' => 'Test Meta Title',
    'meta_description' => 'Test Meta Desc',
];

$test_copy = $test_data;
WooAI_Desc_Product_Handler::filter_by_strategy($test_copy, $strategy);

echo "<h3>After filter_by_strategy('{$strategy}'):</h3>";
echo "<pre>" . print_r($test_copy, true) . "</pre>";

$expected_count = match($strategy) {
    'title' => 1,
    'description' => 1,
    'seo' => 2,
    default => 5,
};

$actual_count = count($test_copy);
$pass = $actual_count === $expected_count;
echo "<p><strong>" . ($pass ? "✅ PASS" : "❌ FAIL") . "</strong> - Expected {$expected_count} fields, got {$actual_count}</p>";
