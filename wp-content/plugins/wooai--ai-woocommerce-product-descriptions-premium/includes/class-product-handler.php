<?php
/**
 * WooCommerce product data handling and AI generation orchestration.
 */

defined('ABSPATH') || exit;

class WooAI_Desc_Product_Handler {

    /**
     * Get paginated product list for the React table.
     */
    public static function get_products(WP_REST_Request $request): WP_REST_Response {
        $search   = $request->get_param('search');
        $category = $request->get_param('category');
        $page     = $request->get_param('page') ?: 1;
        $per_page = $request->get_param('per_page') ?: 20;

        $args = [
            'status'    => 'publish',
            'limit'     => $per_page,
            'page'      => $page,
            'paginate'  => true,
            'orderby'   => 'date',
            'order'     => 'DESC',
        ];

        if ($search) {
            $args['s'] = $search;
        }

        if ($category) {
            $args['category'] = [$category];
        }

        $result = wc_get_products($args);

        $products = array_map(function ($product) {
            return self::format_product($product);
        }, $result->products);

        return new WP_REST_Response([
            'success' => true,
            'data'    => [
                'products'    => $products,
                'total'       => $result->total,
                'total_pages' => $result->max_num_pages,
                'page'        => (int) $page,
            ],
        ], 200);
    }

    /**
     * Get a single product by ID.
     */
    public static function get_product(WP_REST_Request $request): WP_REST_Response {
        $id = (int) $request->get_param('id');
        $product = wc_get_product($id);

        if (!$product) {
            return new WP_REST_Response(['success' => false, 'message' => 'Product not found.'], 404);
        }

        return new WP_REST_Response(['success' => true, 'data' => self::format_product($product)], 200);
    }

    /**
     * Trigger AI generation for one or more products — parallel API calls.
     */
    public static function generate(WP_REST_Request $request): WP_REST_Response {
        $product_ids = $request->get_param('product_ids');
        $strategy    = $request->get_param('strategy') ?: 'all';
        $language    = $request->get_param('language') ?: 'en';
        $tone        = $request->get_param('tone') ?: 'professional';
        $keywords    = $request->get_param('keywords') ?: [];
        $writing_angle = $request->get_param('writing_angle') ?: '';
        $template_id  = $request->get_param('template_id') ?: '';

        // Pro gate removed — this IS the Pro plugin
        // License gate: active subscription required for AI generation
        if (!WooAI_Desc_License::is_pro()) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Your Pro license has expired. Please activate a valid license key.',
            ], 402);
        }

        $api_key = WooAI_Desc_Settings::get('api_key');
        $model   = WooAI_Desc_Settings::get('model');

        if (empty($api_key)) {
            return new WP_REST_Response(['success' => false, 'message' => 'API key not configured. Please set it in Settings.'], 400);
        }

        $api_url = WooAI_Desc_Settings::get_api_url();

        $results = [];

        // Single product — use serial path (simpler, same speed as parallel for 1)
        if (count($product_ids) === 1) {
            $pid = (int) $product_ids[0];
            $product = wc_get_product($pid);
            if (!$product) {
                $results[] = ['product_id' => $pid, 'success' => false, 'error' => 'Product not found'];
            } else {
                $parsed  = null;
                $error   = null;
                $ai      = new WooAI_Desc_AI_Provider($api_key, $model);

                // Retry up to 5 times on JSON parse failure / same-as-original content
                for ($attempt = 1; $attempt <= 5; $attempt++) {
                    try {
                        if ($attempt === 1) {
                            $prompt = WooAI_Desc_Settings::build_prompt(
                                self::format_product($product),
                                compact('strategy', 'language', 'tone', 'keywords', 'writing_angle', 'template_id')
                            );
                        } else {
                            // Simplified prompt on retries
                            $prompt = "CRITICAL: Respond with ONLY valid JSON in {$language}. The content MUST be written in {$language}. Use HTML tags (<strong>, <p>, <br>) for formatting. No markdown. Start with {. Output valid JSON with ALL these fields: title, description, short_description, meta_title, meta_description. Product name: " . ($product->get_name() ?? '');
                        }

                        $raw = $ai->generate($prompt, ['max_tokens' => 4096, 'timeout' => 120]);
                        $raw_parsed = json_decode($raw, true);

                        if (!$raw_parsed) {
                            if ($attempt < 5) continue;
                            $detail = strlen($raw) > 200 ? substr($raw, 0, 200) . '...' : $raw;
                            $error = 'AI returned invalid JSON: ' . $detail;
                            WooAI_Desc_Logger::error('product_handler', "[#{$pid}] JSON parse failed after 5 attempts", [
                                'product_id' => $pid,
                                'model'      => $model,
                                'api_url'    => $api_url,
                                'response'   => $detail,
                            ]);
                            break;
                        }

                        // Check if AI-generated fields match originals
                        self::ensure_complete_json($raw_parsed, $product);
                        $orig_name  = trim($product->get_name());
                        $orig_desc  = trim(wp_strip_all_tags($product->get_description()));
                        $orig_short = trim(wp_strip_all_tags($product->get_short_description()));
                        $gen_title  = trim($raw_parsed['title'] ?? '');
                        $gen_desc   = trim(wp_strip_all_tags($raw_parsed['description'] ?? ''));
                        $gen_short  = trim(wp_strip_all_tags($raw_parsed['short_description'] ?? ''));

                        if ($gen_title === $orig_name && $gen_desc === $orig_desc && $gen_short === $orig_short) {
                            if ($attempt < 5) continue;
                            $error = 'AI returned content identical to original after 5 attempts';
                            WooAI_Desc_Logger::error('product_handler', "[#{$pid}] Redundant content after 5 attempts", [
                                'product_id' => $pid,
                                'model'      => $model,
                            ]);
                            break;
                        }

                        // Success
                        $parsed = $raw_parsed;
                        self::filter_by_strategy($parsed, $strategy);
                        set_transient('wooai_desc_gen_' . $pid, $parsed, HOUR_IN_SECONDS);
                        $results[] = [
                            'product_id' => $pid, 'success' => true, 'generated' => $parsed,
                            'original'   => [
                                'name'              => $product->get_name(),
                                'description'       => wp_strip_all_tags($product->get_description()),
                                'short_description' => wp_strip_all_tags($product->get_short_description()),
                            ],
                        ];
                        break;
                    } catch (Exception $e) {
                        if ($attempt < 5) continue;
                        $error = $e->getMessage();
                        WooAI_Desc_Logger::error('product_handler', "[#{$pid}] Attempt failed after 5 retries", [
                            'product_id' => $pid,
                            'model'      => $model,
                            'error'      => $e->getMessage(),
                        ]);
                        break;
                    }
                }

                if (!$parsed) {
                    $results[] = ['product_id' => $pid, 'success' => false, 'error' => $error ?? 'Unknown error'];
                }
            }
            return new WP_REST_Response(['success' => true, 'data' => $results], 200);
        }

        // Multiple products — parallel via cURL multi
        $mh = curl_multi_init();
        $handles  = [];
        $products = [];

        foreach ($product_ids as $pid) {
            $pid = (int) $pid;
            $product = wc_get_product($pid);
            if (!$product) {
                $results[] = ['product_id' => $pid, 'success' => false, 'error' => 'Product not found'];
                continue;
            }

            $prompt = WooAI_Desc_Settings::build_prompt(
                self::format_product($product),
                compact('strategy', 'language', 'tone', 'keywords', 'writing_angle', 'template_id')
            );

            $body = [
                'model'       => $model,
                'messages'    => [
                    ['role' => 'system', 'content' => "CRITICAL: Respond with ONLY valid JSON in {$language}. The content MUST be written in {$language}. Inside the JSON values, use HTML tags (<strong>, <p>, <br>) for formatting. Do NOT use markdown ** or ##. Start with {. No text before or after the JSON."],
                    ['role' => 'user',   'content' => $prompt],
                ],
                'response_format' => ['type' => 'json_object'],
                'max_tokens'  => 4096,
                'temperature' => 0.7,
            ];

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $api_url,
                CURLOPT_POST           => true,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $api_key,
                ],
                CURLOPT_POSTFIELDS     => json_encode($body),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 120,
                CURLOPT_CONNECTTIMEOUT => 10,
            ]);

            curl_multi_add_handle($mh, $ch);
            $handles[$pid]  = $ch;
            $products[$pid] = $product;
        }

        // Fire all requests in parallel
        $running = null;
        do {
            curl_multi_exec($mh, $running);
            curl_multi_select($mh, 0.5);
        } while ($running > 0);

        // Collect and parse all responses
        foreach ($handles as $pid => $ch) {
            $raw         = curl_multi_getcontent($ch);
            $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curl_error  = curl_error($ch);
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);

            $parsed  = null;
            $error   = null;
            $product_obj = $products[$pid] ?? null;

            // Retry up to 5 times on API error / JSON parse failure / same-as-original content
            for ($attempt = 1; $attempt <= 5; $attempt++) {
                // Product not found — skip
                if (!$product_obj) {
                    $error = 'Product not found';
                    break;
                }

                // Re-do curl request on retries (attempt 1 uses curl_multi response)
                if ($attempt > 1) {
                    $body['messages'][0]['content'] = "CRITICAL: Respond with ONLY valid JSON in {$language}. The content MUST be written in {$language}. Use HTML tags (<strong>, <p>, <br>) inside JSON values. No markdown. Start with {. NO other text.";
                    $body['messages'][1]['content'] = 'Output valid JSON with ALL these fields for this product: title, description, short_description, meta_title, meta_description. Product name: ' . ($product_obj->get_name() ?? '');

                    $ch2 = curl_init();
                    curl_setopt_array($ch2, [
                        CURLOPT_URL            => $api_url,
                        CURLOPT_POST           => true,
                        CURLOPT_HTTPHEADER     => [
                            'Content-Type: application/json',
                            'Authorization: Bearer ' . $api_key,
                        ],
                        CURLOPT_POSTFIELDS     => json_encode($body),
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_TIMEOUT        => 120,
                        CURLOPT_CONNECTTIMEOUT => 10,
                    ]);
                    $raw         = curl_exec($ch2);
                    $http_status = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
                    $curl_error  = curl_error($ch2);
                    curl_close($ch2);
                }

                // Check curl / HTTP errors
                if ($curl_error || $http_status !== 200) {
                    if ($attempt < 5) continue;
                    $err_msg = $curl_error ?: (json_decode($raw, true)['error']['message'] ?? "HTTP {$http_status}");
                    $error = $curl_error ? "API request failed: {$curl_error}" : "AI API error ({$http_status}): {$err_msg}";
                    WooAI_Desc_Logger::error('product_handler', "[#{$pid}] Batch attempt {$attempt} failed", [
                        'product_id'  => $pid,
                        'api_url'     => $api_url,
                        'model'       => $model,
                        'http_status' => $http_status,
                        'error'       => $curl_error ?: ($err_msg ?? ''),
                    ]);
                    break;
                }

                // Parse the response
                $resp_data = json_decode($raw, true);
                $content   = $resp_data['choices'][0]['message']['content'] ?? '';

                // Reasoning content fallback (DeepSeek V4 Pro)
                if (empty(trim($content)) && !empty($resp_data['choices'][0]['message']['reasoning_content'])) {
                    $reasoning = $resp_data['choices'][0]['message']['reasoning_content'];
                    if (preg_match('/\\{[^{}]*"title"\\s*:.*\\}/s', $reasoning, $m)) {
                        $content = $m[0];
                    } elseif (preg_match('/(\\{.*\\}|\\[.*\\])/s', $reasoning, $m)) {
                        $content = $m[0];
                    } else {
                        $content = $reasoning;
                    }
                }

                // Strip markdown fences
                if (preg_match('/```(?:json)?\\s*\\n?(.*?)\\n?```/s', $content, $m)) {
                    $content = $m[1];
                }
                $content = trim($content);

                if ($content && $content[0] !== '{' && $content[0] !== '[') {
                    if (preg_match('/(\\{.*\\}|\\[.*\\])/s', $content, $m)) {
                        $content = $m[0];
                    }
                }

                $parsed = json_decode($content, true);
                if (!$parsed) {
                    if ($attempt < 5) continue;
                    $detail = strlen($content) > 200 ? substr($content, 0, 200) . '...' : $content;
                    $error = 'AI returned invalid JSON: ' . $detail;
                    WooAI_Desc_Logger::error('product_handler', "[#{$pid}] JSON parse failed after 5 attempts", [
                        'product_id' => $pid,
                        'model'      => $model,
                        'api_url'    => $api_url,
                        'response'   => $detail,
                    ]);
                    break;
                }

                // Check if AI-generated fields match originals (generation effectively failed)
                self::ensure_complete_json($parsed, $product_obj);
                $orig_name  = trim($product_obj->get_name());
                $orig_desc  = trim(wp_strip_all_tags($product_obj->get_description()));
                $orig_short = trim(wp_strip_all_tags($product_obj->get_short_description()));
                $gen_title  = trim($parsed['title'] ?? '');
                $gen_desc   = trim(wp_strip_all_tags($parsed['description'] ?? ''));
                $gen_short  = trim(wp_strip_all_tags($parsed['short_description'] ?? ''));

                if ($gen_title === $orig_name && $gen_desc === $orig_desc && $gen_short === $orig_short) {
                    if ($attempt < 5) continue;
                    $error = 'AI returned content identical to original after 5 attempts';
                    WooAI_Desc_Logger::error('product_handler', "[#{$pid}] Redundant content after 5 attempts", [
                        'product_id' => $pid,
                        'model'      => $model,
                    ]);
                    break;
                }

                // Success — fields differ from originals
                self::filter_by_strategy($parsed, $strategy);
                set_transient('wooai_desc_gen_' . $pid, $parsed, HOUR_IN_SECONDS);
                $results[] = [
                    'product_id' => $pid,
                    'success'    => true,
                    'generated'  => $parsed,
                    'original'   => [
                        'name'              => $product_obj->get_name(),
                        'description'       => wp_strip_all_tags($product_obj->get_description()),
                        'short_description' => wp_strip_all_tags($product_obj->get_short_description()),
                    ],
                ];
                break;
            }

            if (!$parsed) {
                $results[] = ['product_id' => $pid, 'success' => false, 'error' => $error ?? 'Unknown error'];
            }
        }

        curl_multi_close($mh);
        return new WP_REST_Response(['success' => true, 'data' => $results], 200);
    }

    /**
     * Apply generated content to a WooCommerce product.
     */
    public static function apply(WP_REST_Request $request): WP_REST_Response {
        $id      = (int) $request->get_param('id');
        $field   = $request->get_param('field');
        $content = $request->get_param('content');

        // Debug: log what we received
        WooAI_Desc_Logger::info('product_handler', "Apply received: field={$field}, content_length=" . strlen($content ?? ''), [
            'product_id' => $id,
            'field'      => $field,
            'length'     => strlen($content ?? ''),
            'preview'    => $content ? mb_substr($content, 0, 100) : 'EMPTY',
        ]);

        $product = wc_get_product($id);
        if (!$product) {
            return new WP_REST_Response(['success' => false, 'message' => 'Product not found.'], 404);
        }

        switch ($field) {
            case 'title':
                $product->set_name(wp_strip_all_tags($content));
                break;
            case 'description':
                $product->set_description(wp_kses_post($content));
                break;
            case 'short_description':
                $product->set_short_description(wp_kses_post($content));
                break;
            case 'meta_title':
                $product->update_meta_data('_yoast_wpseo_title', sanitize_text_field($content));
                $product->update_meta_data('rank_math_title', sanitize_text_field($content));
                break;
            case 'meta_description':
                $product->update_meta_data('_yoast_wpseo_metadesc', sanitize_text_field($content));
                $product->update_meta_data('rank_math_description', sanitize_text_field($content));
                break;
            default:
                return new WP_REST_Response(['success' => false, 'message' => 'Invalid field.'], 400);
        }

        $product->save();

        WooAI_Desc_Logger::info('product_handler', "Applied {$field} to product #{$id}", [
            'product_id' => $id,
            'field'      => $field,
            'length'     => strlen($content),
        ]);

        return new WP_REST_Response(['success' => true, 'message' => 'Applied successfully'], 200);
    }

    /**
     * Apply all generated fields (title + description + meta) to a product at once.
     */
    public static function apply_all(WP_REST_Request $request): WP_REST_Response {
        $id        = (int) $request->get_param('id');
        $generated = $request->get_param('generated');

        if (!is_array($generated)) {
            return new WP_REST_Response(['success' => false, 'message' => 'Invalid generated data.'], 400);
        }

        $product = wc_get_product($id);
        if (!$product) {
            return new WP_REST_Response(['success' => false, 'message' => 'Product not found.'], 404);
        }

        $applied = [];

        if (!empty($generated['title'])) {
            $product->set_name(wp_strip_all_tags($generated['title']));
            $applied[] = 'title';
        }
        if (!empty($generated['description'])) {
            $product->set_description(wp_kses_post($generated['description']));
            $applied[] = 'description';
        }
        if (!empty($generated['short_description'])) {
            $product->set_short_description(wp_kses_post($generated['short_description']));
            $applied[] = 'short_description';
        }
        if (!empty($generated['meta_title'])) {
            $product->update_meta_data('_yoast_wpseo_title', sanitize_text_field($generated['meta_title']));
            $product->update_meta_data('rank_math_title', sanitize_text_field($generated['meta_title']));
            $applied[] = 'meta_title';
        }
        if (!empty($generated['meta_description'])) {
            $product->update_meta_data('_yoast_wpseo_metadesc', sanitize_text_field($generated['meta_description']));
            $product->update_meta_data('rank_math_description', sanitize_text_field($generated['meta_description']));
            $applied[] = 'meta_description';
        }

        $product->save();

        WooAI_Desc_Logger::info('product_handler', "Applied all to product #{$id}", [
            'product_id' => $id,
            'fields'     => $applied,
        ]);

        return new WP_REST_Response([
            'success' => true,
            'message' => 'All fields applied successfully',
            'data'    => ['fields' => $applied],
        ], 200);
    }

    /**
     * Create a product draft from AI-generated content based on keywords.
     */
    public static function create_draft(WP_REST_Request $request): WP_REST_Response {
        $keywords = $request->get_param('keywords') ?: '';
        $language = $request->get_param('language') ?: 'en';
        $tone     = $request->get_param('tone') ?: 'professional';

        if (empty(trim($keywords))) {
            return new WP_REST_Response(['success' => false, 'message' => 'Keywords are required.'], 400);
        }

        // License gate
        if (!WooAI_Desc_License::is_pro()) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Your Pro license has expired. Please activate a valid license key.',
            ], 402);
        }

        $api_key = WooAI_Desc_Settings::get('api_key');
        $model    = WooAI_Desc_Settings::get('model');

        if (empty($api_key)) {
            return new WP_REST_Response(['success' => false, 'message' => 'API key not configured.'], 400);
        }

        try {
            $prompt = self::draft_prompt($keywords, $language, $tone);
            $api_url = WooAI_Desc_Settings::get_api_url();

            $body = [
                'model'       => $model,
                'messages'    => [
                    ['role' => 'system', 'content' => 'CRITICAL: Respond with ONLY valid JSON. Inside JSON values, use HTML tags (<strong>, <p>, <br>). No markdown. Start with {. No explanations.'],
                    ['role' => 'user', 'content' => $prompt],
                ],
                'response_format' => ['type' => 'json_object'],
                'max_tokens'  => 8192,
                'temperature' => 0.8,
            ];

            $response = wp_remote_post($api_url, [
                'timeout' => 120,
                'headers' => [
                    'Content-Type'  => 'application/json',
                    'Authorization' => 'Bearer ' . $api_key,
                ],
                'body' => json_encode($body),
            ]);

            if (is_wp_error($response)) {
                $err = $response->get_error_message();
                WooAI_Desc_Logger::error('product_handler', 'Quick Create WP Error', [
                    'model'   => $model,
                    'api_url' => $api_url,
                    'error'   => $err,
                ]);
                return new WP_REST_Response([
                    'success' => false,
                    'message' => 'WP Error: ' . $response->get_error_message(),
                ], 502);
            }

            $http_status = wp_remote_retrieve_response_code($response);
            $raw_body    = wp_remote_retrieve_body($response);
            
            if ($http_status !== 200) {
                $err_data = json_decode($raw_body, true);
                $err_msg  = $err_data['error']['message'] ?? 'Unknown';
                WooAI_Desc_Logger::error('product_handler', "Quick Create: {$err_msg} (HTTP {$http_status})", [
                    'model'       => $model,
                    'api_url'     => $api_url,
                    'http_status' => $http_status,
                    'error'       => $err_msg,
                    'response'    => substr($raw_body, 0, 500),
                ]);
                return new WP_REST_Response([
                    'success' => false,
                    'message' => "API {$http_status}: {$err_msg}",
                ], 502);
            }

            // Parse the content
            $resp_data = json_decode($raw_body, true);
            $content   = $resp_data['choices'][0]['message']['content'] ?? '';
            
            // DeepSeek V4 Pro: if content is empty, try reasoning_content
            if (empty(trim($content)) && !empty($resp_data['choices'][0]['message']['reasoning_content'])) {
                $reasoning = $resp_data['choices'][0]['message']['reasoning_content'];
                // Try to extract JSON from the end of reasoning
                if (preg_match('/\{[^{}]*"title"\s*:.*\}/s', $reasoning, $m)) {
                    $content = $m[0];
                }
            }
            
            // Strip markdown fences
            if (preg_match('/```(?:json)?\s*\n?(.*?)\n?```/s', $content, $m)) {
                $content = $m[1];
            }
            $content = trim($content);
            
            // Try to extract JSON if wrapped in text
            if ($content && $content[0] !== '{' && $content[0] !== '[') {
                if (preg_match('/(\{.*\}|\[.*\])/s', $content, $m)) {
                    $content = $m[1];
                }
            }

            $data = json_decode($content, true);

            if (!is_array($data)) {
                $preview = $content ? mb_substr($content, 0, 500) : ('HTTP ' . $http_status . ' — empty content. Body: ' . mb_substr($raw_body, 0, 300));
                return new WP_REST_Response([
                    'success' => false,
                    'message' => "Parse failed. Content: {$preview}",
                ], 500);
            }

            // Create WooCommerce product draft with all fields
            $product = new WC_Product_Simple();
            $product->set_name(wp_strip_all_tags($data['title']));
            $product->set_description(wp_kses_post($data['description'] ?? ''));
            $product->set_short_description(wp_kses_post($data['short_description'] ?? ''));
            $product->set_status('draft');
            $product->set_catalog_visibility('visible');

            // Virtual / Downloadable
            if (isset($data['virtual']) && $data['virtual']) {
                $product->set_virtual(true);
            }
            if (isset($data['downloadable']) && $data['downloadable']) {
                $product->set_downloadable(true);
            }

            // Pricing — regular_price is REQUIRED
            if (!empty($data['regular_price'])) {
                $product->set_regular_price(floatval($data['regular_price']));
            } else {
                // Fallback: estimate a reasonable price from context or use a default
                $product->set_regular_price(29.99);
            }
            if (!empty($data['sale_price'])) {
                $product->set_sale_price(floatval($data['sale_price']));
            } elseif (!empty($data['sale_price_dates_from'])) {
                // Sale dates without a sale price makes no sense — clear the dates
                $data['sale_price_dates_from'] = null;
                $data['sale_price_dates_to'] = null;
            }
            if (!empty($data['sale_price_dates_from'])) {
                $product->set_date_on_sale_from(strtotime($data['sale_price_dates_from']));
            }
            if (!empty($data['sale_price_dates_to'])) {
                $product->set_date_on_sale_to(strtotime($data['sale_price_dates_to']));
            }

            // SKU — ensure uniqueness
            if (!empty($data['sku'])) {
                $sku = sanitize_text_field($data['sku']);
                // Append random suffix if SKU already exists
                if (wc_get_product_id_by_sku($sku)) {
                    $sku .= '-' . substr(uniqid(), -4);
                }
                $product->set_sku($sku);
            }

            // Sold individually / Menu order
            if (isset($data['sold_individually']) && $data['sold_individually']) {
                $product->set_sold_individually(true);
            }
            if (isset($data['menu_order'])) {
                $product->set_menu_order(intval($data['menu_order']));
            }

            // Shipping
            if (isset($data['weight'])) $product->set_weight(floatval($data['weight']));
            if (isset($data['length'])) $product->set_length(floatval($data['length']));
            if (isset($data['width']))  $product->set_width(floatval($data['width']));
            if (isset($data['height'])) $product->set_height(floatval($data['height']));
            if (!empty($data['shipping_class'])) {
                $term = get_term_by('name', $data['shipping_class'], 'product_shipping_class');
                if ($term) $product->set_shipping_class_id($term->term_id);
            }

            // Reviews
            if (isset($data['enable_reviews'])) {
                $product->set_reviews_allowed((bool) $data['enable_reviews']);
            }

            // Purchase note
            if (!empty($data['purchase_note'])) {
                $product->set_purchase_note(sanitize_text_field($data['purchase_note']));
            }

            $product_id = $product->save();

            // GTIN — auto-generate valid GTIN-13 from product ID (after save so ID is available)
            $id_str = str_pad($product_id % 100000, 5, '0', STR_PAD_LEFT);
            $ean_base = '200' . str_pad($id_str, 9, '0', STR_PAD_RIGHT);
            $ean_base = substr($ean_base, 0, 12);
            $sum = 0;
            for ($i = 0; $i < 12; $i++) {
                $sum += ($i % 2 === 0 ? 1 : 3) * intval($ean_base[$i]);
            }
            $check = (10 - ($sum % 10)) % 10;
            $gtin = $ean_base . $check;
            $product->update_meta_data('_global_unique_id', $gtin);
            $product->update_meta_data('_gtin', $gtin);
            $product->save();

            // Low stock threshold
            if (isset($data['low_stock_threshold']) && is_numeric($data['low_stock_threshold'])) {
                $product->set_low_stock_amount(intval($data['low_stock_threshold']));
            }

            // Inventory — auto-enable manage_stock if any stock data is provided
            if (isset($data['stock_quantity'])) {
                $product->set_stock_quantity(intval($data['stock_quantity']));
                $product->set_manage_stock(true);
            } elseif (!empty($data['stock_status']) || isset($data['low_stock_threshold'])) {
                $product->set_manage_stock(true);
            }
            if (!empty($data['stock_status'])) {
                $product->set_stock_status($data['stock_status']);
            } elseif (isset($data['stock_quantity']) && intval($data['stock_quantity']) > 0) {
                // Default to in-stock if quantity > 0 but AI didn't provide status
                $product->set_stock_status('instock');
            }

            // Categories
            if (!empty($data['categories']) && is_array($data['categories'])) {
                $cat_ids = [];
                foreach ($data['categories'] as $cat_name) {
                    $term = term_exists($cat_name, 'product_cat');
                    if (!$term) {
                        $term = wp_insert_term($cat_name, 'product_cat');
                    }
                    if (!is_wp_error($term)) {
                        $cat_ids[] = is_array($term) ? $term['term_id'] : $term;
                    }
                }
                if ($cat_ids) {
                    wp_set_object_terms($product_id, $cat_ids, 'product_cat');
                }
            }

            // Tags
            if (!empty($data['tags']) && is_array($data['tags'])) {
                wp_set_object_terms($product_id, array_map('sanitize_text_field', $data['tags']), 'product_tag');
            }

            // Attributes — use custom (non-taxonomy) for reliability
            if (!empty($data['attributes']) && is_array($data['attributes'])) {
                $attrs = [];
                foreach ($data['attributes'] as $attr) {
                    if (empty($attr['name']) || empty($attr['values'])) continue;
                    $attrs[$attr['name']] = [
                        'name'         => $attr['name'],
                        'value'        => implode(' | ', (array) $attr['values']),
                        'position'     => 0,
                        'is_visible'   => isset($attr['visible']) ? (int) $attr['visible'] : 1,
                        'is_variation' => 0,
                        'is_taxonomy'  => 0,
                    ];
                }
                if ($attrs) {
                    update_post_meta($product_id, '_product_attributes', $attrs);
                }
            }

            // SEO meta
            if (!empty($data['meta_title'])) {
                update_post_meta($product_id, '_yoast_wpseo_title', sanitize_text_field($data['meta_title']));
                update_post_meta($product_id, 'rank_math_title', sanitize_text_field($data['meta_title']));
            }
            if (!empty($data['meta_description'])) {
                update_post_meta($product_id, '_yoast_wpseo_metadesc', sanitize_text_field($data['meta_description']));
                update_post_meta($product_id, 'rank_math_description', sanitize_text_field($data['meta_description']));
            }

            // Cross-sell products (by SKU)
            if (isset($data['cross_sell_skus']) && is_array($data['cross_sell_skus']) && !empty($data['cross_sell_skus'])) {
                $cross_ids = [];
                foreach ($data['cross_sell_skus'] as $sku) {
                    $pid = wc_get_product_id_by_sku((string) $sku);
                    if ($pid) $cross_ids[] = $pid;
                }
                if ($cross_ids) {
                    $product->set_cross_sell_ids($cross_ids);
                    $product->save();
                }
            }

            // Brand (custom taxonomy or meta)
            if (!empty($data['brand'])) {
                $brand_name = sanitize_text_field($data['brand']);
                // Try 'product_brand' taxonomy first (common WooCommerce brand plugin)
                if (!taxonomy_exists('product_brand')) {
                    // Fallback: store as custom meta
                    update_post_meta($product_id, '_brand', $brand_name);
                } else {
                    $term = term_exists($brand_name, 'product_brand');
                    if (!$term) {
                        $term = wp_insert_term($brand_name, 'product_brand');
                    }
                    if (!is_wp_error($term)) {
                        $term_id = is_array($term) ? $term['term_id'] : $term;
                        wp_set_object_terms($product_id, [$term_id], 'product_brand');
                    }
                }
            }

            // Auto-fetch product image from Pexels
            $image_id = self::fetch_pexels_image($data['title'], $keywords);
            if ($image_id) {
                $product->set_image_id($image_id);
                $product->save();
            }

            return new WP_REST_Response([
                'success' => true,
                'data'    => [
                    'product_id' => $product_id,
                    'edit_url'   => get_edit_post_link($product_id, 'raw'),
                    'generated'  => $data,
                    'has_image'  => (bool) $image_id,
                ],
            ], 200);

        } catch (Exception $e) {
            return new WP_REST_Response(['success' => false, 'message' => $e->getMessage()], 502);
        }
    }

    /**
     * Build prompt for AI draft generation.
     */

    /**
     * Fetch a product image from Pexels API and attach to media library.
     * Returns attachment ID or null.
     */
    private static function fetch_pexels_image(string $title, string $keywords): ?int {
        // Load required WordPress files for downloading and sideloading images
        if (!function_exists('download_url')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        if (!function_exists('media_handle_sideload')) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        $pexels_key = WooAI_Desc_Settings::get('pexels_api_key');
        if (empty($pexels_key)) {
            return null; // No key configured, skip
        }

        // Build search query from title + keywords
        $query = urlencode($title . ' ' . mb_substr($keywords, 0, 50));

        $response = wp_remote_get("https://api.pexels.com/v1/search?query={$query}&per_page=1&orientation=square&size=medium", [
            'timeout' => 15,
            'headers' => ['Authorization' => $pexels_key],
        ]);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $photo = $body['photos'][0] ?? null;
        if (!$photo || empty($photo['src']['medium'])) {
            return null;
        }

        // Download image to WordPress media library
        $image_url  = $photo['src']['medium'];
        $photographer = $photo['photographer'] ?? 'Pexels';

        $tmp = download_url($image_url);
        if (is_wp_error($tmp)) {
            return null;
        }

        $file_array = [
            'name'     => sanitize_title($title) . '.jpg',
            'tmp_name' => $tmp,
        ];

        $attachment_id = media_handle_sideload($file_array, 0, $title);

        if (is_wp_error($attachment_id)) {
            wp_delete_file($tmp);
            return null;
        }

        // Add alt text and credit
        update_post_meta($attachment_id, '_wp_attachment_image_alt', $title);
        wp_update_post([
            'ID'           => $attachment_id,
            'post_excerpt' => "Photo by {$photographer} via Pexels",
        ]);

        return $attachment_id;
    }

    /**
     * Batch import products from CSV text.
     * CSV columns: Product Name, Keywords
     * Language/Tone from request params (shared with form above).
     */
    public static function batch_import(WP_REST_Request $request): WP_REST_Response {
        $csv_text = $request->get_param('csv');
        $language = $request->get_param('language') ?: 'en';
        $tone     = $request->get_param('tone') ?: 'professional';

        if (empty(trim($csv_text))) {
            return new WP_REST_Response(['success' => false, 'message' => 'CSV content is empty.'], 400);
        }

        $api_key = WooAI_Desc_Settings::get('api_key');
        $model   = WooAI_Desc_Settings::get('model');
        if (empty($api_key)) {
            return new WP_REST_Response(['success' => false, 'message' => 'API key not configured.'], 400);
        }

        // Parse CSV: first line = header, rest = data
        $lines = preg_split('/\r\n|\r|\n/', trim($csv_text));
        if (count($lines) < 2) {
            return new WP_REST_Response(['success' => false, 'message' => 'CSV must have a header row and at least one data row.'], 400);
        }

        $header = str_getcsv($lines[0]);
        // Normalize header names: lowercase, trim
        $header = array_map(function ($h) {
            return trim(strtolower($h));
        }, $header);

        // Find column indices
        $nameIdx = array_search('product name', $header);
        $kwIdx   = array_search('keywords', $header);

        if ($nameIdx === false || $kwIdx === false) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'CSV must have "Product Name" and "Keywords" columns. Download the sample template for the correct format.',
            ], 400);
        }

        // Parse data rows
        $rows = [];
        for ($i = 1; $i < count($lines); $i++) {
            $line = trim($lines[$i]);
            if (empty($line)) continue;
            $data = str_getcsv($line);
            $name = trim($data[$nameIdx] ?? '');
            $kws  = trim($data[$kwIdx] ?? '');
            if (empty($name)) continue;
            $rows[] = ['name' => $name, 'keywords' => $kws];
        }

        if (count($rows) === 0) {
            return new WP_REST_Response(['success' => false, 'message' => 'No valid data rows found in CSV.'], 400);
        }

        if (count($rows) > 10) {
            return new WP_REST_Response(['success' => false, 'message' => 'Maximum 10 rows per batch import.'], 400);
        }

        // License gate
        if (!WooAI_Desc_License::is_pro()) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Your Pro license has expired. Please activate a valid license key.',
            ], 402);
        }

        $api_url = WooAI_Desc_Settings::get_api_url();
        $results = [];

        // Phase 1: Build all AI requests and send them in parallel via curl_multi
        $curl_handles = [];
        $row_map = []; // map index -> row data for phase 2

        foreach ($rows as $idx => $row) {
            $combined_kw = $row['name'];
            if (!empty($row['keywords'])) {
                $combined_kw .= ' — ' . $row['keywords'];
            }

            $prompt = self::draft_prompt($combined_kw, $language, $tone);

            $body = [
                'model'       => $model,
                'messages'    => [
                    ['role' => 'system', 'content' => 'CRITICAL: Respond with ONLY valid JSON. Inside JSON values, use HTML tags (<strong>, <p>, <br>). No markdown. Start with {. No explanations.'],
                    ['role' => 'user', 'content' => $prompt],
                ],
                'response_format' => ['type' => 'json_object'],
                'max_tokens'  => 8192,
                'temperature' => 0.8,
            ];

            $ch = curl_init($api_url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $api_key,
                ],
                CURLOPT_POSTFIELDS     => json_encode($body),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 120,
                CURLOPT_CONNECTTIMEOUT => 30,
            ]);

            $curl_handles[$idx] = $ch;
            $row_map[$idx] = $row;
        }

        // Execute all requests in parallel
        $mh = curl_multi_init();
        foreach ($curl_handles as $ch) {
            curl_multi_add_handle($mh, $ch);
        }

        $running = null;
        do {
            curl_multi_exec($mh, $running);
            curl_multi_select($mh, 5);
        } while ($running > 0);

        // Collect responses
        $responses = [];
        foreach ($curl_handles as $idx => $ch) {
            $raw_body    = curl_multi_getcontent($ch);
            $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curl_error  = curl_error($ch);
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);

            $responses[$idx] = [
                'raw_body'    => $raw_body,
                'http_status' => $http_status,
                'curl_error'  => $curl_error,
            ];
        }
        curl_multi_close($mh);

        // Phase 2: Parse responses and create products sequentially
        foreach ($responses as $idx => $resp) {
            $row = $row_map[$idx];

            // curl error
            if (!empty($resp['curl_error'])) {
                $results[] = [
                    'index'  => $idx + 1,
                    'name'   => $row['name'],
                    'status' => 'error',
                    'error'  => 'cURL: ' . $resp['curl_error'],
                ];
                continue;
            }

            // HTTP error
            if ($resp['http_status'] !== 200) {
                $err_data = json_decode($resp['raw_body'], true);
                $err_msg  = $err_data['error']['message'] ?? 'HTTP ' . $resp['http_status'];
                $results[] = [
                    'index'  => $idx + 1,
                    'name'   => $row['name'],
                    'status' => 'error',
                    'error'  => $err_msg,
                ];
                continue;
            }

            try {
                $resp_data = json_decode($resp['raw_body'], true);
                $content   = $resp_data['choices'][0]['message']['content'] ?? '';

                // DeepSeek V4 Pro fallback
                if (empty(trim($content)) && !empty($resp_data['choices'][0]['message']['reasoning_content'])) {
                    $reasoning = $resp_data['choices'][0]['message']['reasoning_content'];
                    if (preg_match('/\{[^{}]*"title"\s*:.*\}/s', $reasoning, $m)) {
                        $content = $m[0];
                    }
                }

                // Strip markdown fences
                if (preg_match('/```(?:json)?\s*\n?(.*?)\n?```/s', $content, $m)) {
                    $content = $m[1];
                }
                $content = trim($content);

                if ($content && $content[0] !== '{') {
                    if (preg_match('/(\{.*\})/s', $content, $m)) {
                        $content = $m[1];
                    }
                }

                $data = json_decode($content, true);
                if (!is_array($data)) {
                    $results[] = [
                        'index'  => $idx + 1,
                        'name'   => $row['name'],
                        'status' => 'error',
                        'error'  => 'JSON parse failed',
                    ];
                    continue;
                }

                // Override title with CSV Product Name
                $data['title'] = $row['name'];

                // Create WooCommerce product
                $product = new WC_Product_Simple();
                $product->set_name(wp_strip_all_tags($data['title']));
                $product->set_description(wp_kses_post($data['description'] ?? ''));
                $product->set_short_description(wp_kses_post($data['short_description'] ?? ''));
                $product->set_status('draft');
                $product->set_catalog_visibility('visible');

                // Pricing
                if (!empty($data['regular_price'])) {
                    $product->set_regular_price(floatval($data['regular_price']));
                } else {
                    $product->set_regular_price(29.99);
                }
                if (!empty($data['sale_price'])) {
                    $product->set_sale_price(floatval($data['sale_price']));
                } elseif (!empty($data['sale_price_dates_from'])) {
                    $data['sale_price_dates_from'] = null;
                    $data['sale_price_dates_to'] = null;
                }
                if (!empty($data['sale_price_dates_from'])) {
                    $product->set_date_on_sale_from(strtotime($data['sale_price_dates_from']));
                }
                if (!empty($data['sale_price_dates_to'])) {
                    $product->set_date_on_sale_to(strtotime($data['sale_price_dates_to']));
                }

                // SKU
                if (!empty($data['sku'])) {
                    $sku = sanitize_text_field($data['sku']);
                    if (wc_get_product_id_by_sku($sku)) {
                        $sku .= '-' . substr(uniqid(), -4);
                    }
                    $product->set_sku($sku);
                }

                // Virtual / Downloadable
                if (isset($data['virtual']) && $data['virtual']) {
                    $product->set_virtual(true);
                }
                if (isset($data['downloadable']) && $data['downloadable']) {
                    $product->set_downloadable(true);
                }
                if (isset($data['sold_individually']) && $data['sold_individually']) {
                    $product->set_sold_individually(true);
                }

                // Shipping
                if (isset($data['weight']))  $product->set_weight(floatval($data['weight']));
                if (isset($data['length']))  $product->set_length(floatval($data['length']));
                if (isset($data['width']))   $product->set_width(floatval($data['width']));
                if (isset($data['height']))  $product->set_height(floatval($data['height']));
                if (!empty($data['shipping_class'])) {
                    $term = get_term_by('name', $data['shipping_class'], 'product_shipping_class');
                    if ($term) $product->set_shipping_class_id($term->term_id);
                }

                // Reviews & purchase note
                if (isset($data['enable_reviews'])) {
                    $product->set_reviews_allowed((bool) $data['enable_reviews']);
                }
                if (!empty($data['purchase_note'])) {
                    $product->set_purchase_note(sanitize_text_field($data['purchase_note']));
                }

                $product_id = $product->save();

                // GTIN auto-generate
                $id_str = str_pad($product_id % 100000, 5, '0', STR_PAD_LEFT);
                $ean_base = '200' . str_pad($id_str, 9, '0', STR_PAD_RIGHT);
                $ean_base = substr($ean_base, 0, 12);
                $sum = 0;
                for ($i = 0; $i < 12; $i++) {
                    $sum += ($i % 2 === 0 ? 1 : 3) * intval($ean_base[$i]);
                }
                $check = (10 - ($sum % 10)) % 10;
                $gtin = $ean_base . $check;
                $product->update_meta_data('_global_unique_id', $gtin);
                $product->update_meta_data('_gtin', $gtin);
                $product->save();

                // Inventory
                if (isset($data['stock_quantity'])) {
                    $product->set_stock_quantity(intval($data['stock_quantity']));
                    $product->set_manage_stock(true);
                }
                if (!empty($data['stock_status'])) {
                    $product->set_stock_status($data['stock_status']);
                } elseif (isset($data['stock_quantity']) && intval($data['stock_quantity']) > 0) {
                    $product->set_stock_status('instock');
                }

                // Categories
                if (!empty($data['categories']) && is_array($data['categories'])) {
                    $cat_ids = [];
                    foreach ($data['categories'] as $cat_name) {
                        $term = term_exists($cat_name, 'product_cat');
                        if (!$term) {
                            $term = wp_insert_term($cat_name, 'product_cat');
                        }
                        if (!is_wp_error($term)) {
                            $cat_ids[] = is_array($term) ? $term['term_id'] : $term;
                        }
                    }
                    if ($cat_ids) {
                        wp_set_object_terms($product_id, $cat_ids, 'product_cat');
                    }
                }

                // Tags
                if (!empty($data['tags']) && is_array($data['tags'])) {
                    wp_set_object_terms($product_id, array_map('sanitize_text_field', $data['tags']), 'product_tag');
                }

                // Attributes
                if (!empty($data['attributes']) && is_array($data['attributes'])) {
                    $attrs = [];
                    foreach ($data['attributes'] as $attr) {
                        if (empty($attr['name']) || empty($attr['values'])) continue;
                        $attrs[$attr['name']] = [
                            'name'         => $attr['name'],
                            'value'        => implode(' | ', (array) $attr['values']),
                            'position'     => 0,
                            'is_visible'   => isset($attr['visible']) ? (int) $attr['visible'] : 1,
                            'is_variation' => 0,
                            'is_taxonomy'  => 0,
                        ];
                    }
                    if ($attrs) {
                        update_post_meta($product_id, '_product_attributes', $attrs);
                    }
                }

                // SEO meta
                if (!empty($data['meta_title'])) {
                    update_post_meta($product_id, '_yoast_wpseo_title', sanitize_text_field($data['meta_title']));
                    update_post_meta($product_id, 'rank_math_title', sanitize_text_field($data['meta_title']));
                }
                if (!empty($data['meta_description'])) {
                    update_post_meta($product_id, '_yoast_wpseo_metadesc', sanitize_text_field($data['meta_description']));
                    update_post_meta($product_id, 'rank_math_description', sanitize_text_field($data['meta_description']));
                }

                // Pexels auto-image
                $image_id = self::fetch_pexels_image($data['title'], $row['keywords']);
                if ($image_id) {
                    $product->set_image_id($image_id);
                    $product->save();
                }

                $results[] = [
                    'index'      => $idx + 1,
                    'name'       => $row['name'],
                    'status'     => 'success',
                    'product_id' => $product_id,
                    'edit_url'   => get_edit_post_link($product_id, 'raw'),
                ];

            } catch (Exception $e) {
                $results[] = [
                    'index'  => $idx + 1,
                    'name'   => $row['name'],
                    'status' => 'error',
                    'error'  => $e->getMessage(),
                ];
            }
        }

        return new WP_REST_Response([
            'success' => true,
            'data'    => [
                'total'   => count($results),
                'success' => count(array_filter($results, fn($r) => $r['status'] === 'success')),
                'failed'  => count(array_filter($results, fn($r) => $r['status'] === 'error')),
                'results' => $results,
            ],
        ], 200);
    }

    /**
     * Build prompt for AI draft generation.
     */
    private static function draft_prompt(string $keywords, string $language, string $tone): string {
        $langNames = ['en' => 'English', 'zh' => 'Chinese', 'ja' => 'Japanese'];
        $langName  = $langNames[$language] ?? 'English';

        ob_start();
?>
CRITICAL: The response language MUST be <?= $langName ?>. Respond in <?= $langName ?> — NOTHING else. This is a strict requirement.

You are a TOP e-commerce product copywriter with 10+ years of experience at Amazon and Shopify stores. Your listings convert browsers into buyers.

Keywords: <?= $keywords ?>

Language: <?= $langName ?>

Tone: <?= $tone ?>

Return ONLY valid JSON (no markdown, no explanation). Use null for optional fields that are not applicable:

{
  "title": "Convertible Laptop Stand — Adjustable, Ergonomic Aluminum Riser for 10-17\" Notebooks (Silver)",
  "short_description": "Transform your workspace with this premium aluminum laptop stand. 6 adjustable height angles, ventilated design prevents overheating, foldable for travel. Fits all laptops 10-17 inches.",
  "description": "FULL HTML description — see rules below for the required 6+ paragraph structure.",
  "regular_price": 39.99,
  "sale_price": 29.99,
  "sale_price_dates_from": "2026-07-01",
  "sale_price_dates_to": "2026-07-15",
  "sku": "LS-ALUM-39-01",
  "virtual": false,
  "downloadable": false,
  "manage_stock": true,
  "stock_quantity": 200,
  "stock_status": "instock",
  "low_stock_threshold": 10,
  "sold_individually": false,
  "weight": 0.62,
  "length": 26,
  "width": 24,
  "height": 4,
  "shipping_class": "standard",
  "purchase_note": "Your order is backed by a 30-day satisfaction guarantee. If you're not happy, contact us for a full refund — no questions asked.",
  "enable_reviews": true,
  "menu_order": 0,
  "cross_sell_skus": ["LS-CARRY-01", "LS-WRIST-02"],
  "brand": null,
  "meta_title": "Adjustable Aluminum Laptop Stand - Ergonomic & Portable - 10\" to 17\"",
  "meta_description": "Elevate your laptop to eye level with this adjustable aluminum stand. 6 angles, anti-slip grip, open ventilation, folds flat for travel. Fits MacBook, Dell, HP & more. Shop now.",
  "attributes": [
    {"name": "Color", "values": ["Silver", "Space Gray", "Rose Gold"], "visible": true},
    {"name": "Material", "values": ["Aerospace-grade Aluminum"], "visible": true},
    {"name": "Compatibility", "values": ["10\"-17\" Laptops", "MacBook Pro/Air", "Dell XPS", "ThinkPad", "Surface"], "visible": true}
  ],
  "categories": ["Electronics", "Computer Accessories", "Laptop Stands"],
  "tags": ["adjustable laptop stand", "ergonomic workspace", "aluminum laptop riser", "portable laptop stand", "heat dissipation", "anti-slip laptop mount"]
}

CRITICAL DESCRIPTION RULES (description field):

The description MUST be complete HTML using <p>, <ul>, <li>, <strong>, <br>. It MUST have ALL of the following sections — at least 6 paragraphs total:

Paragraph 1 — HOOK: A bold, benefit-driven opening sentence (use <strong>) that immediately grabs attention and answers "Why should I care?"

Paragraph 2 — TOP BENEFITS: 2-3 key selling points framed as benefits, not features. Use <ul><li> format. Each bullet: "X means Y for you."

Paragraph 3 — SPECIFICATIONS: Detailed specs with REAL numbers — weight, dimensions, materials, battery capacity, compatibility, ports, color options, etc. Be specific, not vague.

Paragraph 4 — WHAT'S IN THE BOX: List every item included. Also mention warranty or return policy if applicable.

Paragraph 5 — COMPARISON / WHY THIS IS BETTER: 1-2 sentences comparing to cheaper alternatives or previous versions. Talk about durability, build quality, value over time.

Paragraph 6 — CALL TO ACTION: Urgency or reason to buy now. "Limited stock", "Best price", "Click Add to Cart" etc.

ADDITIONAL RULES:
- short_description: 2-3 compelling sentences that summarize the product. NOT just a shorter version of the first paragraph.
- meta_title: under 60 characters, keyword-rich, click-worthy for Google search results.
- meta_description: 150-160 characters, compelling, includes key selling points and a reason to click.
- use <strong> for the opening hook sentence only. Do not overuse <strong> in the rest of the description.
- NO markdown syntax anywhere. Use HTML tags for formatting.
- Include specific NUMERICAL values wherever possible (weight, dimensions, battery, capacity, quantity, etc.)

PRODUCT DATA RULES:
- regular_price is REQUIRED. Must be a realistic price in USD for the product. Never omit, never null.
- sale_price and sale_price_dates: ALWAYS include a limited-time launch promotion. Set a sale price lower than regular_price, with dates 2-4 weeks in the future. This gives customers urgency and increases conversion.
- weight in kg, dimensions in cm. Be realistic — look up typical values for this product type.
- stock_status: instock | outofstock | onbackorder. Default to instock if stock_quantity > 0.
- shipping_class: standard | bulky | small
- sold_individually: false by default. Only true for limited-edition, high-value, or one-of-a-kind items (signed goods, art prints).
- virtual/downloadable: true for digital goods (software, ebooks, online courses), false for physical products.
- brand: product brand name if obvious from keywords, null otherwise.
- cross_sell_skus: array of 1-3 realistic related product SKUs that pair naturally with this item.
- attributes: ALWAYS generate 2-4 relevant attributes with 2+ values each. This is CRITICAL. Example: Color with multiple color options, Material, Size, Connectivity, etc. Never skip attributes.
- categories: Suggest 1-3 realistic WooCommerce category paths (e.g. "Electronics > Headphones" or "Home & Kitchen > Kitchen Tools").
- tags: At least 5 specific, searchable tags. Use long-tail keywords (e.g. "noise cancelling headphones wireless" not just "headphones").
- purchase_note: Write a reassuring note about warranty, returns, or satisfaction guarantee.
- sku: Generate a realistic SKU pattern (e.g. "PROD-CAT-NNN"). Must look like a real inventory code, not "AUTO-GEN-001".
<?php
        return ob_get_clean();
    }

    /**
     * Format a WC_Product object into an array for the API / AI prompt.
     */
    private static function format_product($product): array {
        $cats = wp_get_post_terms($product->get_id(), 'product_cat', ['fields' => 'names']);
        $tags = wp_get_post_terms($product->get_id(), 'product_tag', ['fields' => 'names']);

        // Collect all product attributes (global + custom)
        $attrs = [];
        foreach ($product->get_attributes() as $name => $attribute) {
            if ($attribute->is_taxonomy()) {
                $terms = wp_get_post_terms($product->get_id(), $name, ['fields' => 'names']);
                if (!empty($terms) && !is_wp_error($terms)) {
                    $label = wc_attribute_label($name);
                    $attrs[] = $label . ': ' . implode(', ', $terms);
                }
            } else {
                $options = $attribute->get_options();
                if (!empty($options)) {
                    $label = $attribute->get_name() ?: $name;
                    $attrs[] = $label . ': ' . implode(', ', $options);
                }
            }
        }

        return [
            'id'                => $product->get_id(),
            'name'              => $product->get_name(),
            'sku'               => $product->get_sku(),
            'price'             => wc_price($product->get_price()),
            'price_raw'         => $product->get_price(),
            'description'       => wp_strip_all_tags($product->get_description()),
            'short_description' => wp_strip_all_tags($product->get_short_description()),
            'categories'        => $cats,
            'tags'              => $tags,
            'attributes'        => implode("\n", $attrs),
            'stock_status'      => $product->get_stock_status(),
            'image'             => wp_get_attachment_url($product->get_image_id()),
            'permalink'         => get_permalink($product->get_id()),
            'meta_title'        => $product->get_meta('_yoast_wpseo_title') ?: $product->get_meta('rank_math_title') ?: $product->get_name(),
            'meta_description'  => $product->get_meta('_yoast_wpseo_metadesc') ?: $product->get_meta('rank_math_description') ?: $product->get_short_description() ?: wp_trim_words($product->get_description(), 20),
        ];
    }

    /**
     * Ensure AI-generated JSON contains ALL required fields.
     * Fills missing fields with sensible defaults derived from product data.
     */
    public static function ensure_complete_json(array &$parsed, $product): void {
        $required = ['title', 'description', 'short_description', 'meta_title', 'meta_description'];
        $defaults = [
            'title'              => $product->get_name(),
            'description'        => $product->get_description() ?: $product->get_name(),
            'short_description'  => $product->get_short_description() ?: substr($product->get_description(), 0, 150),
            'meta_title'         => $product->get_name(),
            'meta_description'   => wp_trim_words($product->get_description() ?: $product->get_name(), 20),
        ];

        foreach ($required as $field) {
            if (empty($parsed[$field])) {
                $parsed[$field] = $defaults[$field];
            }
        }
    }

    /**
     * Strip fields not relevant to the selected strategy from generated data.
     */
    public static function filter_by_strategy(array &$parsed, string $strategy): void {
        $allowed = [];
        switch ($strategy) {
            case 'title':
                $allowed = ['title'];
                break;
            case 'description':
                $allowed = ['description'];
                break;
            case 'seo':
                $allowed = ['meta_title', 'meta_description'];
                break;
            default: // 'all'
                return; // keep everything
        }
        foreach ($parsed as $key => $value) {
            if (!in_array($key, $allowed, true)) {
                unset($parsed[$key]);
            }
        }
    }
}
