<?php
// Temporary debug script - delete after use
require_once __DIR__ . '/wp-load.php';

// Get the fs_accounts option
$accounts = get_option('fs_accounts');
if (!$accounts) {
    die("fs_accounts not found\n");
}

// Find our plugin's pending activation data
$slug_to_fix = 'wooai--ai-woocommerce-product-descriptions';

if (isset($accounts->plugin_data->$slug_to_fix)) {
    $data = &$accounts->plugin_data->$slug_to_fix;
    
    echo "Current state:\n";
    echo "  is_pending_activation: " . ($data->is_pending_activation ? 'true' : 'false') . "\n";
    echo "  pending_license_key: " . ($data->pending_license_key ?? 'none') . "\n";
    echo "  prev_is_premium: " . ($data->prev_is_premium ? 'true' : 'false') . "\n\n";
    
    // Also check ongoing_api_call
    if (isset($data->ongoing_api_call) && $data->ongoing_api_call) {
        echo "  ongoing_api_call: " . print_r($data->ongoing_api_call, true) . "\n";
    }
    
    echo "Clearing pending activation...\n";
    $data->is_pending_activation = false;
    unset($data->pending_license_key);
    $data->prev_is_premium = false;
    
    update_option('fs_accounts', $accounts);
    echo "Done! Pending activation cleared.\n";
    
    // Verify
    $verify = get_option('fs_accounts');
    $vd = $verify->plugin_data->$slug_to_fix;
    echo "After update:\n";
    echo "  is_pending_activation: " . ($vd->is_pending_activation ? 'true' : 'false') . "\n";
    echo "  pending_license_key: " . ($vd->pending_license_key ?? 'none') . "\n";
} else {
    echo "Plugin slug '$slug_to_fix' not found in fs_accounts\n";
    echo "Available slugs:\n";
    foreach ($accounts->plugin_data as $k => $v) {
        echo "  - $k\n";
    }
}
