<?php
/**
 * ================================================================
 *  Plugin Name: DraftLab AI Pro
 *  ================================================================
 *  这是插件的入口文件，WordPress 在加载插件时最先读取这个文件。
 *  通过文件头部的注释（Plugin Name / Description / Version 等）
 *  告诉 WordPress 这是一个插件以及它的基本信息。
 * ================================================================
 */
/**
 * Plugin Name: DraftLab AI Pro - Batch Generator & Product Creator for WooCommerce
 *   └─ 插件名称，显示在 WordPress 后台 → 插件列表页面
 * Plugin URI:  https://draftlab-ai.pro
 *   └─ 插件官方网址
 * Description: Pro version — batch generate descriptions for multiple products...
 *   └─ 插件描述，显示在插件列表页
 * Version: 1.0.22
 *   └─ 当前版本号，更新插件时比对用
 * Author:      WooAI Team
 *   └─ 作者名
 * License:     GPL-2.0-or-later
 *   └─ 开源许可证（GPL 是 WordPress 插件必须遵循的协议）
 * Text Domain: draftlab-ai-pro
 *   └─ 国际化用的文本域（翻译文件识别用）
 * Requires Plugins: woocommerce
 *   └─ 依赖 WooCommerce，自动提醒用户先装 WooCommerce
 * Requires PHP: 7.4
 *   └─ 最低 PHP 版本要求
 * WC requires at least: 5.0
 *   └─ 最低 WooCommerce 版本要求
 *
 * =====================================================================
 *  插件入口 — WordPress 激活/加载时的第一个调用点
 *  职责：检查依赖 → 定义常量 → 加载类文件 → 注册钩子 → 启动插件
 * =====================================================================
 */

// ── 安全防护 ──
// ABSPATH 是 WordPress 定义的常量，代表 WordPress 的根目录绝对路径。
// 如果这个文件被直接通过 URL 访问（而不是由 WordPress 加载），
// ABSPATH 不会被定义，exit 就会终止执行——防止外部直接调用。
defined('ABSPATH') || exit;

// ────────────────────── 常量定义 ──────────────────────

// 插件版本号 —— 用于前端资源（JS/CSS）的缓存无效化，
// 每次发新版时更新这个数字，用户的浏览器就会重新下载资源。
define('WOOAI_DESC_VERSION', '1.0.22');

// 插件目录的绝对路径 —— 例如：/var/www/html/wp-content/plugins/draftlab-ai-pro/
// 用于 require_once 加载其他 PHP 文件。
define('WOOAI_DESC_PATH', plugin_dir_path(__FILE__));

// 插件目录的 URL —— 例如：https://example.com/wp-content/plugins/draftlab-ai-pro/
// 用于前端资源（JS/CSS/图片）的引用。
define('WOOAI_DESC_URL', plugin_dir_url(__FILE__));

// 注意：这个插件没有定义 WOOAI_DESC_DEV 常量。
// 本地开发环境（localhost）和生产环境使用完全相同的代码文件。
// 唯一的区别是通过 freemius-init.php 中的 $_SERVER['HTTP_HOST'] 自动判断：
//   - localhost → 走 Freemius sandbox 模式（测试授权用）
//   - 生产环境 → 走 Freemius live 模式（真实授权验证）

// ─────────────── 1. 检查 WooCommerce 依赖 ───────────────

// admin_init 是 WordPress 的一个钩子（hook），在后台访问时触发。
// 这里用匿名函数（closure）检查 WooCommerce 是否已安装激活。
add_action('admin_init', function () {
    // class_exists('WooCommerce') 检查 WooCommerce 的主类是否存在
    // 如果不存在，说明 WooCommerce 没有激活
    if (!class_exists('WooCommerce')) {
        // deactivate_plugins() —— 自动停用本插件
        // plugin_basename(__FILE__) 获取本文件的插件路径（如 draftlab-ai-pro/wooai-desc.php）
        deactivate_plugins(plugin_basename(__FILE__));

        // 注册一个 admin_notices 钩子，在后台显示错误提示
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p>';
            // esc_html_e() —— 输出经过转义的文本，防止 XSS 攻击
            esc_html_e('DraftLab AI Pro requires WooCommerce to be installed and activated.', 'draftlab-ai-pro');
            echo '</p></div>';
        });
    }
});

// ─────────── 2. 首次启用提示：提醒配置 API Key ───────────

// 当用户进入插件管理页面时，如果还没有配置 AI API Key，
// 显示一条可手动关闭的提示信息，并附上跳转到设置页面的链接。
add_action('admin_notices', function () {
    // 如果 WooCommerce 不存在，直接返回，不显示任何提示
    if (!class_exists('WooCommerce')) return;

    // get_current_screen() 获取当前后台页面的信息
    $screen = get_current_screen();
    // strpos($screen->id, 'wooai-desc') === false —— 如果当前页面不是本插件页面
    // （页面 slug 不包含 wooai-desc），直接返回不显示提示
    if (!$screen || strpos($screen->id, 'wooai-desc') === false) return;

    // 从设置中读取 api_key，如果为空说明没配置
    $api_key = WooAI_Desc_Settings::get('api_key');
    if (empty($api_key)) {
        // is-dismissible —— WordPress 内置 CSS class，让提示可以点击"×"关闭
        echo '<div class="notice notice-info is-dismissible"><p>';
        echo '<strong>AI Product Generator:</strong> ';
        esc_html_e('Please configure your AI Provider API key in Settings to start generating product descriptions.', 'draftlab-ai-pro');
        // admin_url('admin.php?page=wooai-desc') —— 生成本插件设置页面的 URL
        echo ' <a href="' . esc_url(admin_url('admin.php?page=wooai-desc&tab=settings')) . '">Go to Settings</a>';
        echo '</p></div>';
    }
});

// ────────────── 3. 加载所有类文件 ──────────────

// 由于没有使用 Composer 自动加载（autoload），采用最直接的手动 require 方式。
// 加载顺序有讲究——必须先加载依赖类，所以按以下顺序：
// Plugin（核心调度器，负责启动）→ Admin（后台菜单）→ REST API → Settings
// → ProductHandler（业务逻辑）→ AIProvider（AI 通信）→ Logger（日志）→ License（授权）

// class-plugin.php —— 插件核心类 WooAI_Desc_Plugin
// 负责在 plugins_loaded 钩子中按顺序初始化其他模块
require_once WOOAI_DESC_PATH . 'includes/class-plugin.php';

// class-admin.php —— 后台管理类 WooAI_Desc_Admin
// 负责注册 WooCommerce 下的子菜单、加载 React 前端资源
require_once WOOAI_DESC_PATH . 'includes/class-admin.php';

// class-rest-api.php —— REST API 路由类 WooAI_Desc_REST_API
// 定义了所有 15+ 个 /wp-json/wooai-desc/v1/* 端点
require_once WOOAI_DESC_PATH . 'includes/class-rest-api.php';

// class-settings.php —— 设置管理类 WooAI_Desc_Settings
// 负责 API Key、模型、语言、语气等设置的 CRUD
require_once WOOAI_DESC_PATH . 'includes/class-settings.php';

// class-product-handler.php —— 商品处理类 WooAI_Desc_Product_Handler
// 核心业务：AI 生成、Apply 写入、QuickCreate、CSV 批量导入
require_once WOOAI_DESC_PATH . 'includes/class-product-handler.php';

// class-ai-provider.php —— AI 通信类 WooAI_Desc_AI_Provider
// 封装 HTTP 请求，支持任意兼容 OpenAI 的 API 端点
require_once WOOAI_DESC_PATH . 'includes/class-ai-provider.php';

// class-logger.php —— 日志类 WooAI_Desc_Logger
// 创建自定义 MySQL 表存储 AI 调用日志，支持分页查询
require_once WOOAI_DESC_PATH . 'includes/class-logger.php';

// class-license.php —— 授权管理类 WooAI_Desc_License
// 管理 Freemius 授权状态，含自定义 option 缓存策略
require_once WOOAI_DESC_PATH . 'includes/class-license.php';

// ──────────── 4. WordPress 钩子 ────────────

// register_activation_hook —— 插件激活时执行
// 激活时创建日志表（CREATE TABLE IF NOT EXISTS）
register_activation_hook(__FILE__, function () {
    WooAI_Desc_Logger::create_table();
});

// register_deactivation_hook —— 插件停用时执行
// 清除自定义 license 缓存 option，确保下次激活时重新向 Freemius 验证
register_deactivation_hook(__FILE__, function () {
    WooAI_Desc_License::clear_cache();
});

// Freemius SDK — 总是加载（localhost 走 sandbox，生产环境走 live）。
// freemius-init.php 内部会检查 Freemius 类是否已被其他插件加载，
// 如果是则跳过自己的 start.php 以避免类冲突。
require_once WOOAI_DESC_PATH . 'includes/freemius-init.php';

// ──────────── 6. 启动插件 ────────────

// 最后一步：调用 Plugin 类的静态方法 init()
// init() 内部会创建 Singleton 实例，注册 plugins_loaded 钩子
// 等所有插件都加载完成后，再初始化 Settings → Admin → REST API
WooAI_Desc_Plugin::init();
