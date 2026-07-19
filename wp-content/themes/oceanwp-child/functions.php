<?php
/**
 * OceanWP Child Theme Functions
 */




// 从 NestJS API 获取产品列表 - 正确适配版
function get_products_from_nestjs_shortcode() {
    $api_url = 'http://localhost:3000/product';
    $response = wp_remote_get($api_url);

    if (is_wp_error($response)) {
        return '<div style="color:red;">❌ API请求失败</div>';
    }

    $body = wp_remote_retrieve_body($response);
    $result = json_decode($body, true);

    // 取出 data 里的产品数组
    $products = $result['data'] ?? [];

    if (!is_array($products) || empty($products)) {
        return '<div>暂无产品</div>';
    }

    $output = '<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; padding: 20px;">';
    
    foreach ($products as $product) {
        $price = $product['price_usd'] ?? 0;
        $original = $product['price_original_usd'] ?? $price;
        $discount = $original > 0 ? round(100 - ($price / $original * 100)) : 0;

        $output .= '
        <div style="border:1px solid #eee; padding:20px; border-radius:8px; box-shadow:0 2px 5px rgba(0,0,0,0.1);">
            ' . (!empty($product['is_hot']) ? '<span style="background:orange; color:white; padding:2px 8px; border-radius:4px; font-size:12px; margin-right:5px;">HOT</span>' : '') . '
            ' . (!empty($product['is_discount']) ? '<span style="background:red; color:white; padding:2px 8px; border-radius:4px; font-size:12px;">'.$discount.'% OFF</span>' : '') . '
            <h3>'.$product['product_desc_short_en'].'</h3>
            <p><strong>Price:</strong> $'.$price.'</p>
            <p><del>Was:</del> $'.$original.'</del></p>
            <p><strong>Stock:</strong> '.$product['stock_quantity'].'</p>
            <p>ID: '.$product['product_id'].'</p>
        </div>';
    }

    $output .= '</div>';
    return $output;
}
add_shortcode('product_list_nestjs', 'get_products_from_nestjs_shortcode');

// 加载自定义 JS 文件
add_action('wp_enqueue_scripts', function() {
    wp_enqueue_script(
        'my-custom-button',
        get_stylesheet_directory_uri() . '/asset/js/custom-button.js',
        [],
        '1.0',
        true
    );
});