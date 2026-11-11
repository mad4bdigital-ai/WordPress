<?php
/**
 * Plugin Name: JE Fix Missing Script Dependency (Safe)
 * Description: Registers jet-engine-blocks-views when JetEngine references the handle before registering it.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'TK_JE_FIX_MISSING_DEPS_ENABLED' ) ) {
    define( 'TK_JE_FIX_MISSING_DEPS_ENABLED', true );
}

if ( ! defined( 'TK_JE_FIX_MISSING_DEPS_EMPTY_FALLBACK' ) ) {
    define( 'TK_JE_FIX_MISSING_DEPS_EMPTY_FALLBACK', true );
}

if ( ! function_exists( 'je_register_missing_blocks_views_handle' ) ) {
    function je_register_missing_blocks_views_handle() {
        if ( ! TK_JE_FIX_MISSING_DEPS_ENABLED ) {
            return;
        }

        if ( wp_script_is( 'jet-engine-blocks-views', 'registered' ) ) {
            return;
        }

        $deps = array( 'jquery', 'wp-i18n', 'wp-element', 'wp-components', 'wp-blocks' );

        if ( wp_script_is( 'wp-server-side-render', 'registered' ) ) {
            $deps[] = 'wp-server-side-render';
        } elseif ( wp_script_is( 'wp-server-side-render', 'enqueued' ) ) {
            $deps[] = 'wp-server-side-render';
        }

        $candidates = array(
            array(
                'path' => WP_PLUGIN_DIR . '/jet-engine/assets/js/admin/blocks-views.js',
                'url'  => plugins_url( 'jet-engine/assets/js/admin/blocks-views.js' ),
            ),
            array(
                'path' => WP_PLUGIN_DIR . '/jet-engine/includes/modules/blocks-views/assets/js/blocks-views.js',
                'url'  => plugins_url( 'jet-engine/includes/modules/blocks-views/assets/js/blocks-views.js' ),
            ),
            array(
                'path' => WP_PLUGIN_DIR . '/jet-engine/includes/components/blocks-views/assets/js/blocks-views.js',
                'url'  => plugins_url( 'jet-engine/includes/components/blocks-views/assets/js/blocks-views.js' ),
            ),
        );

        foreach ( $candidates as $candidate ) {
            if ( file_exists( $candidate['path'] ) ) {
                wp_register_script(
                    'jet-engine-blocks-views',
                    $candidate['url'],
                    $deps,
                    filemtime( $candidate['path'] ),
                    true
                );
                return;
            }
        }

        if ( is_admin() && TK_JE_FIX_MISSING_DEPS_EMPTY_FALLBACK ) {
            wp_register_script( 'jet-engine-blocks-views', '', array(), null, true );
        }
    }
}

add_action( 'init', 'je_register_missing_blocks_views_handle', 0 );
add_action( 'admin_init', 'je_register_missing_blocks_views_handle', 0 );
add_action( 'enqueue_block_editor_assets', 'je_register_missing_blocks_views_handle', 0 );
add_action( 'elementor/editor/before_enqueue_scripts', 'je_register_missing_blocks_views_handle', 0 );
