<?php
/**
 * REST API route registration.
 */

defined('ABSPATH') || exit;

class WooAI_Desc_REST_API {

    private static $instance = null;
    private const NAMESPACE = 'wooai-desc/v1';

    public static function init(): void {
        if (null === self::$instance) {
            self::$instance = new self();
        }
    }

    private function __construct() {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes(): void {
        // GET /wooai-desc/v1/products — list products
        register_rest_route(self::NAMESPACE, '/products', [
            'methods'             => 'GET',
            'callback'            => ['WooAI_Desc_Product_Handler', 'get_products'],
            'permission_callback' => [$this, 'check_permission'],
            'args'                => [
                'search'   => ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                'category' => ['type' => 'integer'],
                'page'     => ['type' => 'integer', 'default' => 1, 'minimum' => 1],
                'per_page' => ['type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100],
            ],
        ]);

        // GET /wooai-desc/v1/products/{id} — single product
        register_rest_route(self::NAMESPACE, '/products/(?P<id>\d+)', [
            'methods'             => 'GET',
            'callback'            => ['WooAI_Desc_Product_Handler', 'get_product'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        // POST /wooai-desc/v1/generate — trigger AI generation
        register_rest_route(self::NAMESPACE, '/generate', [
            'methods'             => 'POST',
            'callback'            => ['WooAI_Desc_Product_Handler', 'generate'],
            'permission_callback' => [$this, 'check_permission'],
            'args'                => [
                'product_ids' => ['type' => 'array', 'required' => true, 'items' => ['type' => 'integer']],
                'strategy'    => ['type' => 'string', 'required' => true, 'enum' => ['title', 'description', 'seo', 'all']],
                'language'    => ['type' => 'string', 'default' => 'en'],
                'tone'        => ['type' => 'string', 'default' => 'professional'],
                'keywords'    => ['type' => 'array', 'items' => ['type' => 'string']],
                'writing_angle' => ['type' => 'string'],
                'template_id' => ['type' => 'string'],
            ],
        ]);

        // POST /wooai-desc/v1/generate/preview — single product preview
        register_rest_route(self::NAMESPACE, '/generate/preview', [
            'methods'             => 'POST',
            'callback'            => ['WooAI_Desc_Product_Handler', 'generate'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        // POST /wooai-desc/v1/products/{id}/apply — apply single field
        register_rest_route(self::NAMESPACE, '/products/(?P<id>\d+)/apply', [
            'methods'             => 'POST',
            'callback'            => ['WooAI_Desc_Product_Handler', 'apply'],
            'permission_callback' => [$this, 'check_permission'],
            'args'                => [
                'field'   => ['type' => 'string', 'required' => true, 'enum' => ['title', 'description', 'short_description', 'meta_title', 'meta_description']],
                'content' => ['required' => true],
            ],
        ]);

        // POST /wooai-desc/v1/products/{id}/apply-all — apply all generated fields at once
        register_rest_route(self::NAMESPACE, '/products/(?P<id>\d+)/apply-all', [
            'methods'             => 'POST',
            'callback'            => ['WooAI_Desc_Product_Handler', 'apply_all'],
            'permission_callback' => [$this, 'check_permission'],
            'args'                => [
                'generated' => ['type' => 'object', 'required' => true],
            ],
        ]);

        // GET /wooai-desc/v1/settings
        register_rest_route(self::NAMESPACE, '/settings', [
            'methods'             => 'GET',
            'callback'            => ['WooAI_Desc_Settings', 'get_settings'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        // POST /wooai-desc/v1/settings
        register_rest_route(self::NAMESPACE, '/settings', [
            'methods'             => 'POST',
            'callback'            => ['WooAI_Desc_Settings', 'save_settings'],
            'permission_callback' => [$this, 'check_permission'],
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

        // POST /wooai-desc/v1/test-connection — test AI API connection
        register_rest_route(self::NAMESPACE, '/test-connection', [
            'methods'             => 'POST',
            'callback'            => ['WooAI_Desc_Settings', 'test_connection'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        // GET /wooai-desc/v1/templates — list prompt templates
        register_rest_route(self::NAMESPACE, '/templates', [
            'methods'             => 'GET',
            'callback'            => function () {
                return new WP_REST_Response([
                    'success' => true,
                    'data'    => WooAI_Desc_Settings::get_templates(),
                ], 200);
            },
            'permission_callback' => [$this, 'check_permission'],
        ]);

        // POST /wooai-desc/v1/templates — save all prompt templates
        register_rest_route(self::NAMESPACE, '/templates', [
            'methods'             => 'POST',
            'callback'            => function (WP_REST_Request $request) {
                $templates = $request->get_param('templates');
                if (!is_array($templates)) {
                    return new WP_REST_Response(['success' => false, 'message' => 'templates must be an array.'], 400);
                }
                $saved = WooAI_Desc_Settings::save_templates($templates);
                return new WP_REST_Response(['success' => true, 'data' => $saved], 200);
            },
            'permission_callback' => [$this, 'check_permission'],
            'args'                => [
                'templates' => ['type' => 'array', 'required' => true],
            ],
        ]);

        // POST /wooai-desc/v1/products/draft — AI creates product draft
        register_rest_route(self::NAMESPACE, '/products/draft', [
            'methods'             => 'POST',
            'callback'            => ['WooAI_Desc_Product_Handler', 'create_draft'],
            'permission_callback' => [$this, 'check_permission'],
            'args'                => [
                'keywords' => ['type' => 'string', 'required' => true],
                'language' => ['type' => 'string', 'default' => 'en'],
                'tone'     => ['type' => 'string', 'default' => 'professional'],
            ],
        ]);

        // POST /wooai-desc/v1/products/batch-import — batch import from CSV text
        register_rest_route(self::NAMESPACE, '/products/batch-import', [
            'methods'             => 'POST',
            'callback'            => ['WooAI_Desc_Product_Handler', 'batch_import'],
            'permission_callback' => [$this, 'check_permission'],
            'args'                => [
                'csv'      => ['type' => 'string', 'required' => true],
                'language' => ['type' => 'string', 'default' => 'en'],
                'tone'     => ['type' => 'string', 'default' => 'professional'],
            ],
        ]);

        // GET /wooai-desc/v1/license — license status
        register_rest_route(self::NAMESPACE, '/license', [
            'methods'             => 'GET',
            'callback'            => function () {
                $license_info = WooAI_Desc_License::get_license_info();
                return new WP_REST_Response([
                    'success' => true,
                    'data'    => $license_info,
                ], 200);
            },
            'permission_callback' => [$this, 'check_permission'],
        ]);

        // GET /wooai-desc/v1/logs — query logs (admin only)
        register_rest_route(self::NAMESPACE, '/logs', [
            'methods'             => 'GET',
            'callback'            => function (WP_REST_Request $request) {
                $data = WooAI_Desc_Logger::query([
                    'page'     => $request->get_param('page') ?: 1,
                    'per_page' => $request->get_param('per_page') ?: 50,
                    'level'    => $request->get_param('level'),
                    'source'   => $request->get_param('source'),
                    'search'   => $request->get_param('search'),
                ]);
                return new WP_REST_Response(['success' => true, 'data' => $data], 200);
            },
            'permission_callback' => [$this, 'check_admin_permission'],
            'args'                => [
                'page'     => ['type' => 'integer', 'default' => 1],
                'per_page' => ['type' => 'integer', 'default' => 50],
                'level'    => ['type' => 'string'],
                'source'   => ['type' => 'string'],
                'search'   => ['type' => 'string'],
            ],
        ]);

        // GET /wooai-desc/v1/logs/filters — get distinct levels and sources (admin only)
        register_rest_route(self::NAMESPACE, '/logs/filters', [
            'methods'             => 'GET',
            'callback'            => function () {
                return new WP_REST_Response([
                    'success' => true,
                    'data'    => [
                        'levels'  => WooAI_Desc_Logger::get_levels(),
                        'sources' => WooAI_Desc_Logger::get_sources(),
                    ],
                ], 200);
            },
            'permission_callback' => [$this, 'check_admin_permission'],
        ]);

        // POST /wooai-desc/v1/license/activate — activate a license key
        register_rest_route(self::NAMESPACE, '/license/activate', [
            'methods'             => 'POST',
            'callback'            => function (WP_REST_Request $request) {
                $license_key = trim($request->get_param('license_key'));
                if (empty($license_key)) {
                    return new WP_REST_Response(['success' => false, 'message' => 'License key is required.'], 400);
                }

                if (!function_exists('wooai_desc_fs')) {
                    return new WP_REST_Response(['success' => false, 'message' => 'Freemius SDK not loaded.'], 500);
                }

                try {
                    $fs = wooai_desc_fs();

                    // Activate license via Freemius API
                    $site = $fs->get_site();
                    $api = FS_Api::instance(
                        $fs->get_slug(),
                        'install',
                        $site->id,
                        $site->public_key,
                        wooai_desc_is_localhost(),
                        $site->secret_key
                    );

                    $result = $api->call(
                        $fs->add_show_pending('/'),
                        'put',
                        ['license_key' => $license_key]
                    );

                    if (!FS_Api::is_api_error($result)) {
                        // Store the updated site data if available in the response
                        if (is_object($result) && isset($result->id)) {
                            $fs->store_site(new FS_Site($result));
                        }

                        // Also save license status to custom option (reliable cache bypassing
                        // Freemius SDK caching quirks — see class-license.php).
                        $plan_name = 'pro';
                        $expires = null;
                        if (is_object($result) && !empty($result->plan_id)) {
                            $plan_name = 'pro';
                        }
                        // Extract expiration from the API response
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
                        // Fallback: try Freemius SDK cached license
                        if (!$expires && isset($fs->_license) && is_object($fs->_license)) {
                            $expires = $fs->_license->expiration ?? null;
                        }
                        // Get expiration from Freemius license object after sync
                        if (isset($fs->_license) && is_object($fs->_license)) {
                            $expires = $fs->_license->expiration ?? null;
                        }
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
            },
            'permission_callback' => [$this, 'check_permission'],
            'args'                => [
                'license_key' => [
                    'type'     => 'string',
                    'required' => true,
                ],
            ],
        ]);
    }

    public function check_permission(): bool {
        return current_user_can('manage_woocommerce');
    }

    /**
     * Stricter permission check for logs — admin only.
     */
    public function check_admin_permission(): bool {
        return current_user_can('manage_options');
    }
}
