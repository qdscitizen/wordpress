<?php
/**
 * Plugin Name: DraftLab AI Pro - Batch Generator & Product Creator for WooCommerce
 * Plugin URI:  https://draftlab-ai.pro
 * Description: Pro version — batch generate descriptions for multiple products, full product fields, custom AI templates, Quick Create, generation history & diff. Supports DeepSeek & OpenAI.
 * Version: 1.0.22
 * Update URI: https://api.freemius.com
 * Author:      WooAI Team
 * License:     GPL-2.0-or-later
 * Text Domain: draftlab-ai-pro
 * Requires Plugins: woocommerce
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 */

defined('ABSPATH') || exit;

define('WOOAI_DESC_VERSION', '1.0.22');
define('WOOAI_DESC_PATH', plugin_dir_path(__FILE__));
define('WOOAI_DESC_URL', plugin_dir_url(__FILE__));
// NOTE: No WOOAI_DESC_DEV constant.
// Local & production use identical code — Freemius SDK always loads.
// Runtime behavior differs only by $_SERVER['HTTP_HOST'] detection
// in freemius-init.php (localhost → sandbox, production → live).

// Check WooCommerce dependency
add_action('admin_init', function () {
    if (!class_exists('WooCommerce')) {
        deactivate_plugins(plugin_basename(__FILE__));
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p>';
            esc_html_e('DraftLab AI Pro requires WooCommerce to be installed and activated.', 'draftlab-ai-pro');
            echo '</p></div>';
        });
    }
});

// First-run notice: remind user to configure API key
add_action('admin_notices', function () {
    if (!class_exists('WooCommerce')) return;
    $screen = get_current_screen();
    if (!$screen || strpos($screen->id, 'wooai-desc') === false) return;

    $api_key = WooAI_Desc_Settings::get('api_key');
    if (empty($api_key)) {
        echo '<div class="notice notice-info is-dismissible"><p>';
        echo '<strong>AI Product Generator:</strong> ';
        esc_html_e('Please configure your AI Provider API key in Settings to start generating product descriptions.', 'draftlab-ai-pro');
        echo ' <a href="' . esc_url(admin_url('admin.php?page=wooai-desc')) . '">Go to Settings</a>';
        echo '</p></div>';
    }
});

// Load autoloader
require_once WOOAI_DESC_PATH . 'includes/class-plugin.php';
require_once WOOAI_DESC_PATH . 'includes/class-admin.php';
require_once WOOAI_DESC_PATH . 'includes/class-rest-api.php';
require_once WOOAI_DESC_PATH . 'includes/class-settings.php';
require_once WOOAI_DESC_PATH . 'includes/class-product-handler.php';
require_once WOOAI_DESC_PATH . 'includes/class-ai-provider.php';
require_once WOOAI_DESC_PATH . 'includes/class-logger.php';
require_once WOOAI_DESC_PATH . 'includes/class-license.php';

// Create logs table on activation
register_activation_hook(__FILE__, function () {
    WooAI_Desc_Logger::create_table();
});

// Clear license cache on deactivation
register_deactivation_hook(__FILE__, function () {
    WooAI_Desc_License::clear_cache();
});

// Freemius SDK — always load (sandbox on localhost, live on production).
// Note: freemius-init.php internally checks if Freemius class already exists
// (loaded by another plugin) and skips its own start.php to avoid conflicts.
require_once WOOAI_DESC_PATH . 'includes/freemius-init.php';

// Bootstrap
WooAI_Desc_Plugin::init();
