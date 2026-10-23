<?php
/**
 * Admin menu registration and asset enqueueing.
 *
 * 本类负责：
 * 1. 在 WooCommerce 菜单下注册一个"AI Descriptions"子菜单
 * 2. 在用户访问该页面时，加载 React 前端应用（build 后的 JS/CSS）
 * 3. 把 PHP 端的配置信息（API URL、nonce、权限等）传给 JavaScript
 */

defined('ABSPATH') || exit;

class WooAI_Desc_Admin {

    private static $instance = null;

    /**
     * 初始化（外部入口）。
     * 注册 admin_menu 和 admin_enqueue_scripts 两个钩子。
     */
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
     * 在 WooCommerce 主菜单下注册子菜单。
     *
     * 菜单路径：WooCommerce → AI Descriptions
     * 菜单 slug：wooai-desc（用于识别当前页面）
     * 权限：manage_woocommerce（WooCommerce 管理员权限）
     */
    public function register_menu(): void {
        $page_hook = add_submenu_page(
            'woocommerce',                                          // 父菜单 slug
            __('AI Product Generator', 'draftlab-ai-bulk-description-generator-woocommerce'),  // 页面标题
            __('AI Descriptions', 'draftlab-ai-bulk-description-generator-woocommerce'),       // 菜单名称
            'manage_woocommerce',                                    // 所需权限
            'wooai-desc',                                            // 菜单 slug
            [$this, 'render_app']                                    // 渲染回调
        );

        add_action('load-' . $page_hook, [$this, 'on_page_load']);
    }

    /**
     * 页面加载前的钩子（当前未使用，预留）。
     */
    public function on_page_load(): void {
        // 需要时在此添加页面特定逻辑
    }

    /**
     * 渲染 React 应用的容器。
     * 输出一个空的 <div>，React 在客户端渲染到该元素内。
     *
     * 注意：这是 Pro 版，所以没有升级 banner。
     * License 激活通过 React Tab bar 中的"Activate License"按钮处理。
     */
    public function render_app(): void {
        echo '<div id="wooai-desc-root"></div>';
    }

    /**
     * 加载前端资源（JS + CSS）。
     *
     * 只在 slug 包含 "wooai-desc" 的页面加载（不污染其他页面）。
     * 从 webpack 构建产物（build/）中读取依赖列表和版本号。
     *
     * 同时通过 wp_localize_script 向 JavaScript 注入：
     *   - API 端点 URL
     *   - REST nonce（身份验证）
     *   - 当前用户权限
     *   - Freemius 更新/账户 URL
     *
     * @param string $hook 当前页面的 hook 名称
     */
    public function enqueue_assets(string $hook): void {
        if (strpos($hook, 'wooai-desc') === false) {
            return;
        }

        // 读取 webpack 生成的 asset.php（包含依赖列表和版本号）
        // 防御：文件缺失、损坏（require 返回非数组）或键缺失时，全部回退到默认值
        $asset_file = WOOAI_DESC_PATH . 'build/index.asset.php';
        $assets     = file_exists($asset_file) ? require $asset_file : [];

        if (!is_array($assets)) {
            $assets = [];
        }

        $dependencies = $assets['dependencies'] ?? ['react-jsx-runtime', 'wp-components', 'wp-element'];
        $version      = $assets['version'] ?? WOOAI_DESC_VERSION;
        // 加载 JS
        wp_enqueue_script(
            'wooai-desc-admin',
            WOOAI_DESC_URL . 'build/index.js',
            $dependencies,
            $version,
            true
        );

        // 加载 CSS
        wp_enqueue_style(
            'wooai-desc-admin',
            WOOAI_DESC_URL . 'build/index.css',
            ['wp-components'],
            $version
        );

        // 向 JS 注入配置信息
        $upgrade_url = function_exists('wooai_desc_fs')
            ? wooai_desc_fs()->get_upgrade_url()
            : '';
        $account_url = function_exists('wooai_desc_fs')
            ? wooai_desc_fs()->get_account_url()
            : '';

        wp_localize_script('wooai-desc-admin', 'wooaiDesc', [
            'apiUrl'            => rest_url('wooai-desc/v1/'),   // REST API 基地址
            'nonce'             => wp_create_nonce('wp_rest'),  // REST API 认证 nonce
            'is_admin'          => current_user_can('manage_options'),  // 是否是管理员
            'debug'             => defined('WP_DEBUG') && WP_DEBUG,
            'upgradeUrl'        => $upgrade_url,                 // Freemius 升级链接
            'accountUrl'        => $account_url,                 // Freemius 账户页面
            'fsActivateNonce'   => wp_create_nonce('fs_activate_license_29209'),  // Freemius 授权 nonce
            'fsAjaxUrl'         => admin_url('admin-ajax.php'),  // Freemius AJAX 地址
        ]);
    }
}
