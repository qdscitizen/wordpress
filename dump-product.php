<?php
require_once 'D:/xampp/htdocs/wordpress/wp-load.php';

$product_id = 9447;
$product = wc_get_product($product_id);

if (!$product) {
    echo "Product $product_id not found\n";
    $products = wc_get_products(['limit' => 5, 'orderby' => 'date', 'order' => 'DESC']);
    foreach ($products as $p) {
        echo "ID: {$p->get_id()} - {$p->get_name()} ({$p->get_status()})\n";
    }
    exit;
}

echo "=== PRODUCT: {$product->get_name()} ===\n";
echo "SKU: {$product->get_sku()}\n";
echo "Price: {$product->get_regular_price()}";
if ($product->get_sale_price()) echo " (Sale: {$product->get_sale_price()})";
echo "\nSale From: " . ($product->get_date_on_sale_from() ? $product->get_date_on_sale_from()->date('Y-m-d') : 'none') . "\n";
echo "Sale To: " . ($product->get_date_on_sale_to() ? $product->get_date_on_sale_to()->date('Y-m-d') : 'none') . "\n";
echo "Weight: {$product->get_weight()} kg\n";
echo "Dimensions: {$product->get_length()}x{$product->get_width()}x{$product->get_height()} cm\n";
echo "Manage Stock: " . ($product->get_manage_stock() ? 'yes' : 'no') . "\n";
echo "Stock Qty: {$product->get_stock_quantity()}\n";
echo "Backorders: {$product->get_backorders()}\n";
echo "Sold Individually: " . ($product->get_sold_individually() ? 'yes' : 'no') . "\n";
echo "Low Stock: {$product->get_low_stock_amount()}\n";
echo "Purchase Note: {$product->get_purchase_note()}\n";
echo "Reviews Allowed: " . ($product->get_reviews_allowed() ? 'yes' : 'no') . "\n";
echo "Description: " . mb_substr(wp_strip_all_tags($product->get_description()), 0, 300) . "...\n";
echo "Short Desc: " . $product->get_short_description() . "\n";
echo "GTIN: " . get_post_meta($product_id, '_global_unique_id', true) . "\n";

$attrs = get_post_meta($product_id, '_product_attributes', true);
echo "Attributes: " . json_encode($attrs, JSON_UNESCAPED_UNICODE) . "\n";

$cats = wp_get_post_terms($product_id, 'product_cat', ['fields' => 'names']);
echo "Categories: " . implode(', ', $cats) . "\n";

$tags = wp_get_post_terms($product_id, 'product_tag', ['fields' => 'names']);
echo "Tags: " . implode(', ', $tags) . "\n";

echo "Yoast Title: " . get_post_meta($product_id, '_yoast_wpseo_title', true) . "\n";
echo "Yoast Desc: " . get_post_meta($product_id, '_yoast_wpseo_metadesc', true) . "\n";
