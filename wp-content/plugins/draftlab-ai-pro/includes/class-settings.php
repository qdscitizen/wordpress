<?php
/**
 * Plugin settings management.
 *
 * 本类管理插件所有配置项的读写。
 * 配置存储在 WordPress option 表中，键名为 wooai_desc_settings。
 * 包含：API Key、模型选择、语言/语气偏好、Prompt 模板管理。
 *
 * 额外维护 prompt 模板表（wooai_desc_prompt_templates），
 * 这是一个单独的 option，存储用户创建的多套模板。
 */

defined('ABSPATH') || exit;

class WooAI_Desc_Settings {

    private static $instance = null;

    /** @var string 主设置的 option 键名 */
    private const OPTION_KEY = 'wooai_desc_settings';

    /** @var string Prompt 模板 option 键名 */
    private const TEMPLATES_KEY = 'wooai_desc_prompt_templates';

    /** @var array 默认配置值 */
    private static $defaults = [
        'api_key'         => '',          // AI API 密钥
        'model'           => 'deepseek-v4-flash',  // 默认模型
        'api_url'         => 'https://api.deepseek.com/v1/chat/completions', // API 端点
        'language'        => 'en',         // 默认生成语言
        'tone'            => 'professional', // 默认语气
        'max_title_chars' => 90,           // 标题最大字符数
        'max_desc_chars'  => 500,          // 描述最大字符数
        'prompt_template' => '',           // 自定义 Prompt 模板
        'pexels_api_key'  => '',           // Pexels 图片 API 密钥（用于 QuickCreate 配图）
    ];

    // ── 初始化 ──

    public static function init(): void {
        if (null === self::$instance) {
            self::$instance = new self();
        }
    }

    // ── 读取设置 ──

    /**
     * 获取所有设置（已填充默认值）。
     * 注意：返回的 api_key 是完整值，前端用 api_key_masked 显示（脱敏后）。
     *
     * @return array
     */
    public static function get_all(): array {
        $saved = get_option(self::OPTION_KEY, []);
        $settings = wp_parse_args($saved, self::$defaults);

        // 额外生成脱敏后的 API Key（前端显示用）
        $settings['api_key_masked'] = self::mask($settings['api_key']);

        return $settings;
    }

    /**
     * 获取单个设置项。
     *
     * @param string $key 设置项名称
     * @return mixed|null 不存在返回 null
     */
    public static function get(string $key) {
        $all = self::get_all();
        return $all[$key] ?? null;
    }

    /**
     * 保存设置（以 JSON 发送时适用）。
     * 只保存传入了的字段，未传的字段保留原值不覆盖。
     *
     * @param array $input 要保存的键值对
     * @return array 保存后的完整设置
     */
    public static function save(array $input): array {
        $sanitized = [];

        if (isset($input['api_key'])) {
            $sanitized['api_key'] = sanitize_text_field($input['api_key']);
        }
        if (isset($input['model'])) {
            $sanitized['model'] = sanitize_text_field($input['model']);
        }
        if (isset($input['api_url'])) {
            $sanitized['api_url'] = esc_url_raw($input['api_url']);
        }
        if (isset($input['language'])) {
            $sanitized['language'] = sanitize_text_field($input['language']);
        }
        if (isset($input['tone'])) {
            $sanitized['tone'] = sanitize_text_field($input['tone']);
        }
        if (isset($input['prompt_template'])) {
            $sanitized['prompt_template'] = wp_kses_post($input['prompt_template']);
        }
        if (isset($input['pexels_api_key'])) {
            $sanitized['pexels_api_key'] = sanitize_text_field($input['pexels_api_key']);
        }

        // 合并已有设置 → 只覆盖传了的值，不传的不动
        $existing = get_option(self::OPTION_KEY, []);
        $merged = wp_parse_args($sanitized, $existing);
        update_option(self::OPTION_KEY, $merged);

        return self::get_all();
    }

    // ── REST API 回调 ──

    /**
     * GET /settings 的回调。
     */
    public static function get_settings(): WP_REST_Response {
        return new WP_REST_Response(['success' => true, 'data' => self::get_all()], 200);
    }

    /**
     * POST /settings 的回调。
     */
    public static function save_settings(WP_REST_Request $request): WP_REST_Response {
        $saved = self::save($request->get_params());
        return new WP_REST_Response(['success' => true, 'data' => $saved], 200);
    }

    /**
     * POST /test-connection 的回调。
     * 用当前（或传参）的 API Key + 模型 + URL 发一条测试请求。
     * 成功 → 返回 success: true
     * 失败 → 返回错误信息（HTTP 502）
     */
    public static function test_connection(WP_REST_Request $request): WP_REST_Response {
        $api_key = $request->get_param('api_key') ?: self::get('api_key');
        $model   = $request->get_param('model') ?: self::get('model');
        $api_url = $request->get_param('api_url') ?: self::get('api_url');

        if (empty($api_key)) {
            return new WP_REST_Response(['success' => false, 'message' => 'API key is required.'], 400);
        }

        try {
            $ai = new WooAI_Desc_AI_Provider($api_key, $model);
            $ai->set_api_url($api_url);
            $ai->generate('ok', ['max_tokens' => 2, 'is_test' => true]);
            return new WP_REST_Response([
                'success'  => true,
                'message'  => 'Connection successful',
            ], 200);
        } catch (Exception $e) {
            $err = $e->getMessage();
            WooAI_Desc_Logger::error('settings', "Test connection failed: {$err}", [
                'api_url' => $api_url,
                'model'   => $model,
                'error'   => $e->getMessage(),
            ]);
            return new WP_REST_Response(['success' => false, 'message' => $e->getMessage()], 502);
        }
    }

    // ── Prompt 模板管理 ──

    /**
     * 获取所有已保存的 Prompt 模板。
     * 每条模板：{ id, name, tone, language, writing_angle, prompt }
     *
     * @return array
     */
    public static function get_templates(): array {
        $templates = get_option(self::TEMPLATES_KEY, []);
        return is_array($templates) ? $templates : [];
    }

    /**
     * 根据 ID 查找单个模板。
     *
     * @param string $id 模板 ID
     * @return array|null
     */
    public static function get_template(string $id): ?array {
        foreach (self::get_templates() as $t) {
            if (($t['id'] ?? '') === $id) {
                return $t;
            }
        }
        return null;
    }

    /**
     * 覆盖保存所有模板（全量替换）。
     *
     * @param array $templates 模板数组，每项：{ id, name, tone, language, writing_angle, prompt }
     * @return array 清理后的模板数组
     */
    public static function save_templates(array $templates): array {
        $cleaned = [];
        foreach ($templates as $t) {
            $cleaned[] = [
                'id'            => sanitize_text_field($t['id'] ?? uniqid('tmpl_')),
                'name'          => sanitize_text_field($t['name'] ?? 'Untitled'),
                'tone'          => sanitize_text_field($t['tone'] ?? ''),
                'language'      => sanitize_text_field($t['language'] ?? ''),
                'writing_angle' => sanitize_text_field($t['writing_angle'] ?? ''),
                'prompt'        => wp_kses_post($t['prompt'] ?? ''),
            ];
        }
        update_option(self::TEMPLATES_KEY, $cleaned);
        return $cleaned;
    }

    /**
     * 构建完整的 Prompt（带 placeholder 替换）。
     *
     * 流程：
     *   1. 如果传了 template_id → 读取保存的模板 prompt
     *   2. 未传 → 用设置的 prompt_template
     *   3. 都没有 → 用内置默认 prompt
     *   4. 替换 {{product_name}}、{{language}} 等占位符
     *
     * @param array $product_data 商品数据（name, description, sku, categories, 等）
     * @param array $options       生成参数（strategy, language, tone, keywords, writing_angle, template_id）
     * @return string 替换完成后的完整 Prompt 文本
     */
    public static function build_prompt(array $product_data, array $options): string {
        // ── 1. 确定模板来源 ──
        $template_id = $options['template_id'] ?? null;
        $template = null;

        if ($template_id) {
            $found = self::get_template($template_id);
            if ($found) {
                $template = $found['prompt'];
            }
        }

        if (empty($template)) {
            $template = self::get('prompt_template');
        }

        if (empty($template)) {
            $template = self::default_prompt();
        }

        // ── 2. 根据 strategy 生成输出字段定义 ──
        $strategy = $options['strategy'] ?? 'all';
        $output_fields = [];
        switch ($strategy) {
            case 'title':
                $output_fields = ['"title": "SEO optimized title"'];
                break;
            case 'description':
                $output_fields = ['"description": "Product description with HTML formatting (use <strong>, <p>, <br>)"'];
                break;
            case 'seo':
                $output_fields = [
                    '"meta_title": "SEO meta title"',
                    '"meta_description": "SEO meta description"',
                ];
                break;
            default: // 'all'
                $output_fields = [
                    '"title": "SEO optimized title"',
                    '"description": "Product description with HTML formatting (use <strong>, <p>, <br>)"',
                    '"short_description": "Short excerpt"',
                    '"meta_title": "SEO meta title"',
                    '"meta_description": "SEO meta description"',
                ];
        }
        $output_json = "{\n  " . implode(",\n  ", $output_fields) . "\n}";

        // ── 3. 替换占位符 ──
        $replacements = [
            '{{product_name}}'        => $product_data['name'] ?? '',
            '{{product_description}}' => $product_data['description'] ?? '',
            '{{product_sku}}'         => $product_data['sku'] ?? '',
            '{{product_categories}}'  => implode(', ', $product_data['categories'] ?? []),
            '{{product_tags}}'        => implode(', ', $product_data['tags'] ?? []),
            '{{product_attributes}}'  => $product_data['attributes'] ?? '',
            '{{product_price}}'       => $product_data['price'] ?? '',
            '{{strategy}}'            => $strategy,
            '{{language}}'            => $options['language'] ?? 'English',
            '{{tone}}'                => $options['tone'] ?? 'professional',
            '{{keywords}}'            => implode(', ', $options['keywords'] ?? []),
            '{{writing_angle}}'       => $options['writing_angle'] ?? '',
            '{{max_title_chars}}'     => self::get('max_title_chars'),
            '{{max_desc_chars}}'      => self::get('max_desc_chars'),
            '{{output_format}}'       => $output_json,
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $template);
    }

    /**
     * 内置默认 Prompt（当用户未配置自定义模板时使用）。
     * 包含所有标准要求和占位符。
     *
     * @return string
     */
    private static function default_prompt(): string {
        ob_start();
?>
CRITICAL: The response language MUST be {{language}}. Respond in {{language}} — NOTHING else. This is a strict requirement.

You are an expert e-commerce copywriter and SEO specialist.
Generate optimized product content for a WooCommerce store.

Product Information:
- Name: {{product_name}}
- SKU: {{product_sku}}
- Categories: {{product_categories}}
- Tags: {{product_tags}}
- Attributes:
{{product_attributes}}
- Current Description: {{product_description}}
- Price: {{product_price}}

Requirements:
- Strategy: {{strategy}} (title=SEO title only, description=product description, seo=meta tags, all=everything)
- Language: {{language}}
- Tone: {{tone}}
- Keywords to include: {{keywords}}
{{writing_angle}}
- Title max characters: {{max_title_chars}}
- Description max characters: {{max_desc_chars}}

Output format (JSON) — ONLY include the fields below, nothing else:

{{output_format}}

Return valid JSON only, no other text. YOUR RESPONSE MUST START WITH { AND END WITH }. DO NOT INCLUDE ANY TEXT BEFORE OR AFTER THE JSON.
<?php
        return ob_get_clean();
    }

    /**
     * 获取配置的 API URL（支持 filter 钩子覆盖）。
     *
     * @return string
     */
    public static function get_api_url(): string {
        $saved = self::get('api_url');
        if (!empty($saved)) {
            return rtrim($saved, '/');
        }
        return apply_filters('wooai_desc_api_url', 'https://api.deepseek.com/v1/chat/completions');
    }

    /**
     * 脱敏 API Key（前端显示用）。
     * "sk-abcdefghijklmnop" → "sk-ab***********mnop"
     *
     * @param string $key 原始 API Key
     * @return string 脱敏后的字符串（不足 8 位返回空）
     */
    private static function mask(string $key): string {
        if (empty($key) || strlen($key) < 8) {
            return '';
        }
        return substr($key, 0, 4) . str_repeat('*', max(0, strlen($key) - 8)) . substr($key, -4);
    }
}
