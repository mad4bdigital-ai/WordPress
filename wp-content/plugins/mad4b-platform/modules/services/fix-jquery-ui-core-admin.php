<?php
/**
 * Plugin Name: Fix jquery-ui-core missing in admin (Switchable)
 * Description: Admin-only recovery shim for missing jQuery UI / pointer dependencies.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'TK_FIX_JQUERY_UI_CORE_ADMIN_ENABLED' ) ) {
    define( 'TK_FIX_JQUERY_UI_CORE_ADMIN_ENABLED', true );
}

if ( ! defined( 'TK_FIX_JQUERY_UI_CORE_ADMIN_POINTER_ENABLED' ) ) {
    define( 'TK_FIX_JQUERY_UI_CORE_ADMIN_POINTER_ENABLED', true );
}

if ( ! function_exists( 'tk_fix_jquery_ui_core_admin_enqueue' ) ) {
    function tk_fix_jquery_ui_core_admin_enqueue() {
        if ( ! TK_FIX_JQUERY_UI_CORE_ADMIN_ENABLED ) {
            return;
        }

        if ( wp_script_is( 'jquery-ui-core', 'registered' ) ) {
            wp_enqueue_script( 'jquery-ui-core' );
        }

        if ( ! TK_FIX_JQUERY_UI_CORE_ADMIN_POINTER_ENABLED ) {
            return;
        }

        if ( wp_script_is( 'wp-i18n', 'registered' ) ) {
            wp_enqueue_script( 'wp-i18n' );
        }

        if ( wp_script_is( 'wp-pointer', 'registered' ) ) {
            wp_enqueue_script( 'wp-pointer' );
        }

        if ( wp_style_is( 'wp-pointer', 'registered' ) ) {
            wp_enqueue_style( 'wp-pointer' );
        }
    }
}

add_action( 'admin_enqueue_scripts', 'tk_fix_jquery_ui_core_admin_enqueue', 1 );
