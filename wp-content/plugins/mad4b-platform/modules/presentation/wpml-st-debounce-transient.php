<?php
/**
 * Plugin Name: DD - WPML ST Debounce transient invalidation
 * Description: Debounces WPML String Translation MO-domain cache invalidation so the same invalidation runs once per request.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'TK_WPML_ST_DEBOUNCE_ENABLED' ) ) {
    define( 'TK_WPML_ST_DEBOUNCE_ENABLED', true );
}

if ( ! defined( 'TK_WPML_ST_DEBOUNCE_LOG' ) ) {
    define( 'TK_WPML_ST_DEBOUNCE_LOG', false );
}

if ( ! function_exists( 'tk_wpml_st_debounce_log' ) ) {
    function tk_wpml_st_debounce_log( $message ) {
        if ( TK_WPML_ST_DEBOUNCE_LOG && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[TK WPML ST Debounce] ' . $message );
        }
    }
}

add_action( 'plugins_loaded', function () {
    if ( ! TK_WPML_ST_DEBOUNCE_ENABLED ) {
        return;
    }

    $domains_class = 'WPML\\ST\\TranslationFile\\Domains';
    $callback      = array( $domains_class, 'invalidateMODomainCache' );

    if ( ! class_exists( $domains_class ) || ! is_callable( $callback ) ) {
        return;
    }

    $hooks = array(
        'wpml_st_strings_table_altered',
        'wpml_st_string_registered',
        'wpml_st_string_unregistered',
        'wpml_st_string_updated',
    );

    foreach ( $hooks as $hook ) {
        $priority = has_action( $hook, $callback );

        if ( false !== $priority ) {
            remove_action( $hook, $callback, $priority );
            tk_wpml_st_debounce_log( sprintf( 'Removed native invalidation from %s at priority %s.', $hook, (string) $priority ) );
        }
    }

    $once = function () use ( $callback ) {
        static $ran = false;

        if ( $ran ) {
            return;
        }

        $ran = true;

        try {
            call_user_func( $callback );
            tk_wpml_st_debounce_log( 'Ran debounced invalidation once.' );
        } catch ( Throwable $e ) {
            tk_wpml_st_debounce_log( 'Debounced invalidation failed: ' . $e->getMessage() );
        }
    };

    foreach ( $hooks as $hook ) {
        add_action( $hook, $once, 0 );
    }
}, 9999 );
