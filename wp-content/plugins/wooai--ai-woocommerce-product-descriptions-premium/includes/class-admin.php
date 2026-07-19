<?php
/**
 * Admin menu registration and asset enqueueing.
 */

defined('ABSPATH') || exit;

class WooAI_Desc_Admin {

    private static $instance = null;

    public static function init(): void {
        if (null === self::$instance) {
            self::$instance = new self();
        }
    }

    private function __construct() {
        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    /**
     * Register submenu under WooCommerce.
     */
    public function register_menu(): void {
        $page_hook = add_submenu_page(
            'woocommerce',                              // parent slug
            __('AI Product Generator', 'draftlab-ai-bulk-description-generator-woocommerce'),    // page title
            __('AI Descriptions', 'draftlab-ai-bulk-description-generator-woocommerce'),         // menu title
            'manage_woocommerce',                        // capability
            'wooai-desc',                                // menu slug
            [$this, 'render_app']                        // callback
        );

        add_action('load-' . $page_hook, [$this, 'on_page_load']);
    }

    /**
     * Actions before rendering the admin page.
     */
    public function on_page_load(): void {
        // Nothing specific needed yet.
    }

    /**
     * Render the React app container.
     */
    public function render_app(): void {
        // No upgrade banner needed — this IS the Pro plugin.
        // License activation is handled by the Activate License button in the React tab bar.
        echo '<div id="wooai-desc-root"></div>';
    }

    /**
     * Enqueue React assets only on our admin page.
     */
    public function enqueue_assets(string $hook): void {
        if (strpos($hook, 'wooai-desc') === false) {
            return;
        }

        $asset_file = WOOAI_DESC_PATH . 'build/index.asset.php';
        $assets = file_exists($asset_file)
            ? require $asset_file
            : ['dependencies' => ['wp-element', 'wp-components', 'wp-api-fetch'], 'version' => WOOAI_DESC_VERSION];

        wp_enqueue_script(
            'wooai-desc-admin',
            WOOAI_DESC_URL . 'build/index.js',
            $assets['dependencies'],
            $assets['version'],
            true
        );

        wp_enqueue_style(
            'wooai-desc-admin',
            WOOAI_DESC_URL . 'build/index.css',
            ['wp-components'],
            $assets['version']
        );

        // Pass config to React
        $upgrade_url = function_exists('wooai_desc_fs')
            ? wooai_desc_fs()->get_upgrade_url()
            : '';
        $account_url = function_exists('wooai_desc_fs')
            ? wooai_desc_fs()->get_account_url()
            : '';

        wp_localize_script('wooai-desc-admin', 'wooaiDesc', [
            'apiUrl'            => rest_url('wooai-desc/v1/'),
            'nonce'             => wp_create_nonce('wp_rest'),
            'is_admin'          => current_user_can('manage_options'),
            'debug'             => defined('WP_DEBUG') && WP_DEBUG,
            'upgradeUrl'        => $upgrade_url,
            'accountUrl'        => $account_url,
            'fsActivateNonce'   => wp_create_nonce('fs_activate_license_29209'),
            'fsAjaxUrl'         => admin_url('admin-ajax.php'),
        ]);
    }
}
