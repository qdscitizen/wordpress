<?php
/**
 * REST API 路由注册 + 控制器。
 *
 * 本类定义了插件所有的 REST API 端点。
 * 前端 React 应用通过这些端点与后端通信——没有传统的 admin-ajax.php。
 *
 * 路由前缀：/wp-json/wooai-desc/v1/
 * 权限控制：普通操作需 manage_woocommerce 权限，日志查询需 manage_options（管理员）
 *
 * 代码风格约定（全项目统一）：
 *   1. 本类是全静态类，不实例化、不持有状态；
 *   2. 所有 callback / permission_callback 一律写成 [类名::class, '方法名']，
 *      不使用内联闭包，也不使用 [$this, '方法名']；
 *   3. register_routes() 只负责"注册"，具体业务逻辑放在下方对应的静态方法里。
 */

defined('ABSPATH') || exit;

class WooAI_Desc_REST_API {

    /**
     * REST API 命名空间前缀。
     * 所有路由形如：/wp-json/wooai-desc/v1/{endpoint}
     */
    private const NAMESPACE = 'wooai-desc/v1';

    /**
     * 初始化 REST API。外部入口（由 WooAI_Desc_Plugin::init() 调用）。
     *
     * 为什么这个类是全静态的？
     *   本类不保存任何状态——路由注册和权限检查都不需要实例属性。
     *   以前用 Singleton（$instance + 私有 __construct）只是为了把一个无状态的
     *   动作挂到 rest_api_init 上，等于凭空多一层实例化；
     *   改成全静态后，回调与权限检查的写法全部统一为 [类::class, '方法名']，
     *   和 Product_Handler / Settings / Logger 三个兄弟类保持一致。
     */
    public static function init(): void {
        add_action('rest_api_init', [self::class, 'register_routes']);
    }

    /**
     * 注册所有 REST API 路由。
     * 路由分类概览：
     *   ├── /products — 商品查询
     *   ├── /generate — AI 生成（预览 + 批量）
     *   ├── /products/{id}/apply — 写入单个字段
     *   ├── /products/{id}/apply-all — 写入全部字段
     *   ├── /settings — 设置读写
     *   ├── /test-connection — AI API 连通性测试
     *   ├── /templates — Prompt 模板管理
     *   ├── /products/draft — AI 创建商品草稿
     *   ├── /products/batch-import — CSV 批量导入
     *   ├── /license — 授权状态查询
     *   ├── /license/activate — 激活授权密钥
     *   ├── /logs — 生成历史日志（管理员）
     *   └── /logs/filters — 日志筛选项（管理员）
     */
    public static function register_routes(): void {

        // ───── 商品列表 ─────
        // GET /wooai-desc/v1/products
        // 参数：search(模糊搜索), category(分类ID), page(页码), per_page(每页条数)
        register_rest_route(self::NAMESPACE, '/products', [
            'methods'             => 'GET',
            'callback'            => [WooAI_Desc_Product_Handler::class, 'get_products'],
            'permission_callback' => [self::class, 'check_permission'],
            'args'                => [
                'search'   => ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                'category' => ['type' => 'integer'],
                'page'     => ['type' => 'integer', 'default' => 1, 'minimum' => 1],
                'per_page' => ['type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100],
            ],
        ]);

        // ───── 单个商品详情 ─────
        // GET /wooai-desc/v1/products/{id}
        register_rest_route(self::NAMESPACE, '/products/(?P<id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [WooAI_Desc_Product_Handler::class, 'get_product'],
            'permission_callback' => [self::class, 'check_permission'],
        ]);

        // ───── AI 生成（核心功能）─────
        // POST /wooai-desc/v1/generate
        // 参数：product_ids(必须), strategy(title/description/seo/all),
        //       language, tone, keywords, writing_angle, template_id
        // 前端 Generator 页面和 Preview 模式都走这个端点，
        // 区别仅在于 product_ids 传一个还是多个。
        register_rest_route(self::NAMESPACE, '/generate', [
            'methods'             => 'POST',
            'callback'            => [WooAI_Desc_Product_Handler::class, 'generate'],
            'permission_callback' => [self::class, 'check_permission'],
            'args'                => [
                'product_ids'  => ['type' => 'array', 'required' => true, 'items' => ['type' => 'integer']],
                'strategy'     => ['type' => 'string', 'required' => true, 'enum' => ['title', 'description', 'seo', 'all']],
                'language'     => ['type' => 'string', 'default' => 'en'],
                'tone'         => ['type' => 'string', 'default' => 'professional'],
                'keywords'     => ['type' => 'array', 'items' => ['type' => 'string']],
                'writing_angle' => ['type' => 'string'],
                'template_id'  => ['type' => 'string'],
            ],
        ]);

        // ───── 单商品预览 ─────
        // POST /wooai-desc/v1/generate/preview
        // 与 /generate 共用的 generate() 方法，
        // 前端传入 1 个 product_id 即达到预览效果。
        register_rest_route(self::NAMESPACE, '/generate/preview', [
            'methods'             => 'POST',
            'callback'            => [WooAI_Desc_Product_Handler::class, 'generate'],
            'permission_callback' => [self::class, 'check_permission'],
        ]);

        // ───── 写入单个字段 ─────
        // POST /wooai-desc/v1/products/{id}/apply
        // 将 AI 生成的某字段内容写回 WooCommerce 商品。
        // field: title / description / short_description / meta_title / meta_description
        // content: 要写入的内容
        register_rest_route(self::NAMESPACE, '/products/(?P<id>\d+)/apply', [
            'methods'             => 'POST',
            'callback'            => [WooAI_Desc_Product_Handler::class, 'apply'],
            'permission_callback' => [self::class, 'check_permission'],
            'args'                => [
                'field'   => ['type' => 'string', 'required' => true, 'enum' => ['title', 'description', 'short_description', 'meta_title', 'meta_description']],
                'content' => ['required' => true],
            ],
        ]);

        // ───── 写入全部字段 ─────
        // POST /wooai-desc/v1/products/{id}/apply-all
        // 一次性将生成的 title + description + short_desc + meta 全部写入
        // generated: 包含所有字段的对象
        register_rest_route(self::NAMESPACE, '/products/(?P<id>\d+)/apply-all', [
            'methods'             => 'POST',
            'callback'            => [WooAI_Desc_Product_Handler::class, 'apply_all'],
            'permission_callback' => [self::class, 'check_permission'],
            'args'                => [
                'generated' => ['type' => 'object', 'required' => true],
            ],
        ]);

        // ───── 读取设置 ─────
        // GET /wooai-desc/v1/settings
        register_rest_route(self::NAMESPACE, '/settings', [
            'methods'             => 'GET',
            'callback'            => [WooAI_Desc_Settings::class, 'get_settings'],
            'permission_callback' => [self::class, 'check_permission'],
        ]);

        // ───── 保存设置 ─────
        // POST /wooai-desc/v1/settings
        // 参数：api_key, model, api_url, language, tone, prompt_template, pexels_api_key
        register_rest_route(self::NAMESPACE, '/settings', [
            'methods'             => 'POST',
            'callback'            => [WooAI_Desc_Settings::class, 'save_settings'],
            'permission_callback' => [self::class, 'check_permission'],
            'args'                => [
                'api_key'         => ['type' => 'string', 'required' => true],
                'model'           => ['type' => 'string', 'required' => true],
                'api_url'         => ['type' => 'string'],
                'language'        => ['type' => 'string'],
                'tone'            => ['type' => 'string'],
                'prompt_template' => ['type' => 'string'],
                'pexels_api_key'  => ['type' => 'string'],
            ],
        ]);

        // ───── AI API 连通性测试 ─────
        // POST /wooai-desc/v1/test-connection
        // 用当前配置的 API Key 请求 AI 服务，验证连通性。
        // 设置页面点"Test Connection"时调用。
        register_rest_route(self::NAMESPACE, '/test-connection', [
            'methods'             => 'POST',
            'callback'            => [WooAI_Desc_Settings::class, 'test_connection'],
            'permission_callback' => [self::class, 'check_permission'],
        ]);

        // ───── 列出 Prompt 模板 ─────
        // GET /wooai-desc/v1/templates
        register_rest_route(self::NAMESPACE, '/templates', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'get_templates'],
            'permission_callback' => [self::class, 'check_permission'],
        ]);

        // ───── 保存 Prompt 模板 ─────
        // POST /wooai-desc/v1/templates
        // 一次性保存所有模板（覆盖式）
        register_rest_route(self::NAMESPACE, '/templates', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'save_templates'],
            'permission_callback' => [self::class, 'check_permission'],
            'args'                => [
                'templates' => ['type' => 'array', 'required' => true],
            ],
        ]);

        // ───── AI 创建商品草稿 ─────
        // POST /wooai-desc/v1/products/draft
        // 输入关键词，让 AI 自动生成完整的商品（名称 + 描述等）
        // 对应 QuickCreate 页面
        register_rest_route(self::NAMESPACE, '/products/draft', [
            'methods'             => 'POST',
            'callback'            => [WooAI_Desc_Product_Handler::class, 'create_draft'],
            'permission_callback' => [self::class, 'check_permission'],
            'args'                => [
                'keywords' => ['type' => 'string', 'required' => true],
                'language' => ['type' => 'string', 'default' => 'en'],
                'tone'     => ['type' => 'string', 'default' => 'professional'],
            ],
        ]);

        // ───── CSV 批量导入商品 ─────
        // POST /wooai-desc/v1/products/batch-import
        // 传入 CSV 格式文本（用 AI 解析），批量创建商品
        register_rest_route(self::NAMESPACE, '/products/batch-import', [
            'methods'             => 'POST',
            'callback'            => [WooAI_Desc_Product_Handler::class, 'batch_import'],
            'permission_callback' => [self::class, 'check_permission'],
            'args'                => [
                'csv'      => ['type' => 'string', 'required' => true],
                'language' => ['type' => 'string', 'default' => 'en'],
                'tone'     => ['type' => 'string', 'default' => 'professional'],
            ],
        ]);

        // ───── 授权状态查询 ─────
        // GET /wooai-desc/v1/license
        // 返回：is_paid(布尔), plan_name, expires(时间戳), is_active(布尔)
        // 前端根据此响应控制 Modal 弹窗 / 功能门控
        register_rest_route(self::NAMESPACE, '/license', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'get_license'],
            'permission_callback' => [self::class, 'check_permission'],
        ]);

        // ───── 查询生成日志 ─────
        // GET /wooai-desc/v1/logs
        // 参数：page, per_page, level(info/warn/error), source, search
        // 权限：manage_options（管理员专属，普通用户看不到）
        register_rest_route(self::NAMESPACE, '/logs', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'get_logs'],
            'permission_callback' => [self::class, 'check_admin_permission'],
            'args'                => [
                'page'     => ['type' => 'integer', 'default' => 1],
                'per_page' => ['type' => 'integer', 'default' => 50],
                'level'    => ['type' => 'string'],
                'source'   => ['type' => 'string'],
                'search'   => ['type' => 'string'],
            ],
        ]);

        // ───── 日志筛选器选项 ─────
        // GET /wooai-desc/v1/logs/filters
        // 返回日志中所有出现过的 level 和 source 值，供前端下拉框使用
        register_rest_route(self::NAMESPACE, '/logs/filters', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'get_log_filters'],
            'permission_callback' => [self::class, 'check_admin_permission'],
        ]);

        // ───── 激活授权密钥 ─────
        // POST /wooai-desc/v1/license/activate
        // 流程：
        //   1. 接收 license_key
        //   2. 用 FS_Api 调用 Freemius 服务端 API 验证并激活
        //   3. 从返回结果中提取 plan_name 和过期时间
        //   4. 保存到自定义 option（绕过 Freemius SDK 缓存问题）
        // 注意：Freemius SDK 的 sync_install() 只更新 _site 不更新 _license
        // 缓存，所以这里手动保存到自定义键 wooai_desc_license_data。
        register_rest_route(self::NAMESPACE, '/license/activate', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'activate_license'],
            'permission_callback' => [self::class, 'check_permission'],
            'args'                => [
                'license_key' => [
                    'type'     => 'string',
                    'required' => true,
                ],
            ],
        ]);
    }


    // ─────────────── 路由回调（原先写在 register_rest_route 里的内联闭包）───────────────

    /**
     * GET /templates —— 返回全部 Prompt 模板。
     *
     * 为什么把闭包抽成独立静态方法？
     *   1. register_routes() 的职责只有"注册路由"，业务逻辑不该混在里面；
     *   2. 抽出来后回调写法统一成 [类::class, '方法名']，可读、可 IDE 跳转；
     *   3. 闭包没法被单独调用或单测，静态方法可以。
     */
    public static function get_templates(): WP_REST_Response {
        return new WP_REST_Response([
            'success' => true,
            'data'    => WooAI_Desc_Settings::get_templates(),
        ], 200);
    }

    /**
     * POST /templates —— 覆盖式保存全部模板。
     * 这里只做参数校验，真正落库交给 WooAI_Desc_Settings::save_templates()。
     */
    public static function save_templates(WP_REST_Request $request): WP_REST_Response {
        $templates = $request->get_param('templates');
        if (!is_array($templates)) {
            return new WP_REST_Response(['success' => false, 'message' => 'templates must be an array.'], 400);
        }
        $saved = WooAI_Desc_Settings::save_templates($templates);
        return new WP_REST_Response(['success' => true, 'data' => $saved], 200);
    }

    /**
     * GET /license —— 返回授权状态，前端据此控制弹窗与功能门控。
     * 只读，不写任何 option。
     */
    public static function get_license(): WP_REST_Response {
        $license_info = WooAI_Desc_License::get_license_info();
        return new WP_REST_Response([
            'success' => true,
            'data'    => $license_info,
        ], 200);
    }

    /**
     * GET /logs —— 分页查询生成日志。
     * 管理员专属，由 permission_callback 的 check_admin_permission 把关。
     */
    public static function get_logs(WP_REST_Request $request): WP_REST_Response {
        $data = WooAI_Desc_Logger::query([
            'page'     => $request->get_param('page') ?: 1,
            'per_page' => $request->get_param('per_page') ?: 50,
            'level'    => $request->get_param('level'),
            'source'   => $request->get_param('source'),
            'search'   => $request->get_param('search'),
        ]);
        return new WP_REST_Response(['success' => true, 'data' => $data], 200);
    }

    /**
     * GET /logs/filters —— 返回日志里出现过的 level / source，供前端筛选下拉框使用。
     */
    public static function get_log_filters(): WP_REST_Response {
        return new WP_REST_Response([
            'success' => true,
            'data'    => [
                'levels'  => WooAI_Desc_Logger::get_levels(),
                'sources' => WooAI_Desc_Logger::get_sources(),
            ],
        ], 200);
    }

    /**
     * POST /license/activate —— 激活授权密钥。
     * 流程：接收 license_key → 用 FS_Api 调 Freemius 服务端验证并激活
     *      → 提取 plan_name 与过期时间 → 存进自定义 option。
     */
    public static function activate_license(WP_REST_Request $request): WP_REST_Response {
        $license_key = trim($request->get_param('license_key'));
        if (empty($license_key)) {
            return new WP_REST_Response(['success' => false, 'message' => 'License key is required.'], 400);
        }

        if (!function_exists('wooai_desc_fs')) {
            return new WP_REST_Response(['success' => false, 'message' => 'Freemius SDK not loaded.'], 500);
        }

        try {
            $fs = wooai_desc_fs();

            // 获取当前安装（site）信息
            $site = $fs->get_site();
            // 创建 Freemius API 实例，通过 /installs/{id} 端点更新 license
            $api = FS_Api::instance(
                $fs->get_slug(),
                'install',
                $site->id,
                $site->public_key,
                wooai_desc_is_localhost(),
                $site->secret_key
            );

            // PUT 请求激活 license（相当于 Freemius 后台的"激活"操作）
            $result = $api->call(
                $fs->add_show_pending('/'),
                'put',
                ['license_key' => $license_key]
            );

            if (!FS_Api::is_api_error($result)) {
                // 更新 Freemius SDK 内部的 site 缓存
                if (is_object($result) && isset($result->id)) {
                    $fs->store_site(new FS_Site($result));
                }

                // 保存到自定义 option（绕开 SDK 缓存问题）
                $plan_name = 'pro';
                $expires = null;

                // 从 API 响应中提取过期时间（可能在不同字段位置）
                if (is_object($result) && isset($result->license) && is_object($result->license)) {
                    if (!empty($result->license->expiration)) {
                        $expires = is_numeric($result->license->expiration)
                            ? (int) $result->license->expiration
                            : strtotime($result->license->expiration);
                    }
                } elseif (is_object($result) && !empty($result->expiration)) {
                    $expires = is_numeric($result->expiration)
                        ? (int) $result->expiration
                        : strtotime($result->expiration);
                }

                // ── 过期时间到此为止，不再往下读 SDK 缓存 ──
                // 为什么不读 $fs->_license？
                //   1. Freemius::$_license 是 private 属性（freemius/includes/class-freemius.php:240），
                //      且 SDK 没有定义 __get / __isset 魔术方法；
                //   2. 所以从外部 isset($fs->_license) 恒为 false，而且不会报错（静默失败）——
                //      以前这里写的两段 isset 兜底实际上是死代码，从来没有执行过。
                //   3. 过期时间的唯一可信来源是上面的 API 响应（$result），
                //      以及 WooAI_Desc_License::get_license_info() 里的 /licenses.json 实时查询。
                // 如果将来确实要读 SDK 缓存，只能用公开方法 $fs->_get_license()，
                // 但那会让 get_license_info() 的 API 兜底分支被跳过，可能读到过期的缓存值，改动前务必评估。

                // 写入自定义缓存
                WooAI_Desc_License::set_license_active($plan_name, $expires);

                return new WP_REST_Response([
                    'success' => true,
                    'message' => 'License activated successfully!',
                ], 200);
            }

            $error_msg = FS_Api::is_api_error_object($result)
                ? $result->error->message
                : 'License activation rejected by Freemius.';

            return new WP_REST_Response(['success' => false, 'message' => $error_msg], 400);
        } catch (Exception $e) {
            return new WP_REST_Response(['success' => false, 'message' => 'Error: ' . $e->getMessage()], 500);
        }
    }

    // ─────────────── 权限检查 ───────────────

    /**
     * 权限检查（常规操作）——需要 WooCommerce 管理权限。
     * manage_woocommerce 是 WooCommerce 的标准权限，店铺管理员一般都有。
     */
    public static function check_permission(): bool {
        return current_user_can('manage_woocommerce');
    }

    /**
     * 权限检查（管理操作）——需要站点管理员权限。
     * 用于日志查询这类敏感操作，防止普通店员看到。
     */
    public static function check_admin_permission(): bool {
        return current_user_can('manage_options');
    }
}
