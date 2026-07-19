<?php
/**
 * Plugin settings management.
 */

defined('ABSPATH') || exit;

class WooAI_Desc_Settings {

    private static $instance = null;
    private const OPTION_KEY = 'wooai_desc_settings';
    private const TEMPLATES_KEY = 'wooai_desc_prompt_templates';

    private static $defaults = [
        'api_key'         => '',
        'model'           => 'deepseek-v4-flash',
        'api_url'         => 'https://api.deepseek.com/v1/chat/completions',
        'language'        => 'en',
        'tone'            => 'professional',
        'max_title_chars' => 90,
        'max_desc_chars'  => 500,
        'prompt_template' => '',
        'pexels_api_key'  => '',
    ];

    public static function init(): void {
        if (null === self::$instance) {
            self::$instance = new self();
        }
    }

    /**
     * Get all settings (with defaults filled).
     */
    public static function get_all(): array {
        $saved = get_option(self::OPTION_KEY, []);
        $settings = wp_parse_args($saved, self::$defaults);

        // Never expose raw API key in full — mask it for response
        $settings['api_key_masked'] = self::mask($settings['api_key']);

        return $settings;
    }

    /**
     * Get a single setting.
     */
    public static function get(string $key) {
        $all = self::get_all();
        return $all[$key] ?? null;
    }

    /**
     * Save settings.
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

        // Merge with existing to avoid overwriting unset fields
        $existing = get_option(self::OPTION_KEY, []);
        $merged = wp_parse_args($sanitized, $existing);
        update_option(self::OPTION_KEY, $merged);

        return self::get_all();
    }

    /**
     * REST API callback: GET settings.
     */
    public static function get_settings(): WP_REST_Response {
        return new WP_REST_Response(['success' => true, 'data' => self::get_all()], 200);
    }

    /**
     * REST API callback: POST settings.
     */
    public static function save_settings(WP_REST_Request $request): WP_REST_Response {
        $saved = self::save($request->get_params());
        return new WP_REST_Response(['success' => true, 'data' => $saved], 200);
    }

    /**
     * REST API callback: test AI connection.
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

    // ---- Prompt Templates ----

    /**
     * Get all saved prompt templates.
     * @return array List of {id, name, prompt} objects.
     */
    public static function get_templates(): array {
        $templates = get_option(self::TEMPLATES_KEY, []);
        return is_array($templates) ? $templates : [];
    }

    /**
     * Find a template by ID.
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
     * Replace all templates with a new list.
     * Each item: { id, name, prompt }
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
     * Build the system prompt with placeholders replaced.
     * Pass $options['template_id'] to use a saved template; null uses settings prompt_template.
     */
    public static function build_prompt(array $product_data, array $options): string {
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

        // Build dynamic output format based on strategy
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
     * Get the configured API URL from settings, with filter fallback.
     */
    public static function get_api_url(): string {
        $saved = self::get('api_url');
        if (!empty($saved)) {
            return rtrim($saved, '/');
        }
        return apply_filters('wooai_desc_api_url', 'https://api.deepseek.com/v1/chat/completions');
    }

    /**
     * Mask API key for display.
     */
    private static function mask(string $key): string {
        if (empty($key) || strlen($key) < 8) {
            return '';
        }
        return substr($key, 0, 4) . str_repeat('*', max(0, strlen($key) - 8)) . substr($key, -4);
    }
}
