<?php
/**
 * Main plugin class.
 */

defined('ABSPATH') || exit;

class WooAI_Desc_Plugin {

    private static $instance = null;

    public static function init(): void {
        if (null === self::$instance) {
            self::$instance = new self();
        }
    }

    private function __construct() {
        add_action('plugins_loaded', [$this, 'on_plugins_loaded']);
    }

    public function on_plugins_loaded(): void {
        if (!class_exists('WooCommerce')) {
            return;
        }

        WooAI_Desc_Settings::init();
        WooAI_Desc_Admin::init();
        WooAI_Desc_REST_API::init();
    }

    public static function get_version(): string {
        return WOOAI_DESC_VERSION;
    }

    public static function log($message): void {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[WooAI Desc] ' . print_r($message, true));
        }
    }
}
