<?php
/**
 * Main plugin class.
 *
 * 插件核心调度器——所有模块的启动入口。
 * 在插件完全加载（plugins_loaded 钩子）后，按顺序初始化各个模块。
 * 使用 Singleton 模式确保只初始化一次。
 */

defined('ABSPATH') || exit;

class WooAI_Desc_Plugin {

    /**
     * Singleton 实例
     * @var self|null
     */
    private static $instance = null;

    /**
     * 初始化插件（外部唯一入口）。
     * 在 wooai-desc.php 末尾被调用。
     */
    public static function init(): void {
        if (null === self::$instance) {
            self::$instance = new self();
        }
    }

    /**
     * 构造函数（私有）。
     * 注册 plugins_loaded 钩子——等所有插件都装好后初始化。
     */
    private function __construct() {
        add_action('plugins_loaded', [$this, 'on_plugins_loaded']);
    }

    /**
     * 在 plugins_loaded 时执行。
     * 检查 WooCommerce 是否存在，然后按顺序初始化：
     *   1. Settings（加载设置项）
     *   2. Admin（注册 admin 菜单 + 页面 + 前端资源）
     *   3. REST API（注册所有 API 路由）
     */
    public function on_plugins_loaded(): void {
        if (!class_exists('WooCommerce')) {
            return;
        }

        WooAI_Desc_Settings::init();
        WooAI_Desc_Admin::init();
        WooAI_Desc_REST_API::init();
    }

    /**
     * 获取插件版本号（用于前端资源缓存无效化等）。
     * @return string
     */
    public static function get_version(): string {
        return WOOAI_DESC_VERSION;
    }

    /**
     * 调试日志工具（仅在 WP_DEBUG 开启时生效）。
     * @param mixed $message 日志内容（可以是字符串或数组）
     */
    public static function log($message): void {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[WooAI Desc] ' . print_r($message, true));
        }
    }
}
