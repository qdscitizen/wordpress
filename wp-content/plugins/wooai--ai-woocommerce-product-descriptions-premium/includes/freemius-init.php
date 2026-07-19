<?php

/**
 * Freemius initialization — WooAI Desc
 * Plugin ID: 29209
 *
 * IMPORTANT: Code is 100% identical between local and production.
 * The only runtime difference is detecting whether we're on localhost:
 *   - localhost → is_org_compliant=false (Account page visible), sandbox checkout
 *   - production → is_org_compliant=true (WordPress.org compliant), live checkout
 */
defined( 'ABSPATH' ) || exit;
/**
 * Detect whether the current request is on localhost.
 * Only runtime variable — everything else is identical code.
 */
function wooai_desc_is_localhost() : bool {
    $host = ( isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '' );
    return in_array( $host, ['localhost', '127.0.0.1', '::1'] );
}

if ( !function_exists( 'wooai_desc_fs' ) ) {
    function wooai_desc_fs() {
        global $wooai_desc_fs;
        if ( !isset( $wooai_desc_fs ) ) {
            // 如果别的插件已经加载了 Freemius SDK，复用它的类定义
            // 避免加载自己的 start.php 时缺少某些类（如 FS_Plugin_Updater）
            if ( !class_exists( 'Freemius' ) ) {
                require_once WOOAI_DESC_PATH . 'freemius/start.php';
            }
            $is_localhost = wooai_desc_is_localhost();
            $wooai_desc_fs = fs_dynamic_init( [
                'id'                => '29209',
                'slug'              => 'wooai--ai-woocommerce-product-descriptions',
                'type'              => 'plugin',
                'public_key'        => 'pk_d41cfb649f168c3b821605a4fc5d6',
                'is_premium'        => true,
                'premium_suffix'    => 'Pro',
                'has_addons'        => false,
                'has_paid_plans'    => true,
                'enable_anonymous'  => false,
                'is_sandbox'        => $is_localhost,
                'is_org_compliant'  => !$is_localhost,
                'wp_org_gatekeeper' => 'OA7#BoRiBNqdf52FvzEf!!074aRLPs8fspif$7K1#4u4Csys1fQlCecVcUTOs2mcpeVHi#C2j9d09fOTvbC0HloPT7fFee5WdS3G',
                'menu'              => [
                    'slug'    => 'wooai-desc',
                    'parent'  => [
                        'slug' => 'woocommerce',
                    ],
                    'support' => false,
                ],
                'is_live'           => true,
            ] );
            // On localhost: force sandbox mode in checkout URL
            // (same code path, just ?sandbox=true param difference)
            if ( $is_localhost ) {
                $wooai_desc_fs->add_filter( 'checkout_url', function ( $url ) {
                    return add_query_arg( 'sandbox', 'true', $url );
                } );
                $wooai_desc_fs->add_filter( 'https_localhost_redirect', '__return_false' );
            }
        }
        return $wooai_desc_fs;
    }

    wooai_desc_fs();
    do_action( 'wooai_desc_fs_loaded' );
    // WP.org compatibility: remove Freemius plugin updater hooks
    // (updates are handled by WordPress.org)
    add_action( 'init', function () {
        remove_filter( 'site_transient_update_plugins', ['Freemius', '_remove_fs_updates_from_plugin_install_page'], 10 );
    }, 999 );
}