<?php
/**
 * Plugin Name: Elementor Dynamic Tag Probe
 * Description: Logs widgets/controls that pass arrays into dynamic tags to help pinpoint the offender. Disabled by default.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'TK_ELEMENTOR_DTAG_PROBE' ) || ! TK_ELEMENTOR_DTAG_PROBE ) {
    return;
}

if ( ! defined( 'TK_ELEMENTOR_DTAG_PROBE_LOG_MAX_CHARS' ) ) {
    define( 'TK_ELEMENTOR_DTAG_PROBE_LOG_MAX_CHARS', 3000 );
}

if ( ! function_exists( 'tk_elementor_dtag_probe_encode_for_log' ) ) {
    function tk_elementor_dtag_probe_encode_for_log( $value ) {
        $json = wp_json_encode( $value );
        if ( ! is_string( $json ) ) {
            return '[json_encode_failed]';
        }

        $max = (int) TK_ELEMENTOR_DTAG_PROBE_LOG_MAX_CHARS;
        if ( $max > 0 && strlen( $json ) > $max ) {
            return substr( $json, 0, $max ) . '...[truncated]';
        }

        return $json;
    }
}

add_action( 'elementor/frontend/widget/before_render', function( $widget ) {
    if ( ! is_object( $widget ) || ! method_exists( $widget, 'get_active_settings' ) ) {
        return;
    }

    $settings = $widget->get_active_settings();
    if ( ! is_array( $settings ) ) {
        return;
    }

    foreach ( $settings as $key => $val ) {
        if ( is_array( $val ) ) {
            error_log( sprintf(
                '[DTAG-ARRAY][WIDGET]%s|id=%s|control=%s|value=%s',
                method_exists( $widget, 'get_name' ) ? $widget->get_name() : 'unknown',
                method_exists( $widget, 'get_id' ) ? $widget->get_id() : 'unknown',
                $key,
                tk_elementor_dtag_probe_encode_for_log( $val )
            ) );
        }
    }
}, 10, 1 );

$__log_array_dtag = static function( $value, $tag = null ) {
    if ( is_array( $value ) ) {
        $tag_name = ( is_object( $tag ) && method_exists( $tag, 'get_name' ) ) ? $tag->get_name() : 'unknown';
        error_log( sprintf(
            '[DTAG-ARRAY][TAG]%s|value=%s',
            $tag_name,
            tk_elementor_dtag_probe_encode_for_log( $value )
        ) );
    }

    return $value;
};

add_filter( 'elementor/dynamic_tags/parse', $__log_array_dtag, 10, 2 );
add_filter( 'elementor_pro/dynamic_tags/parse', $__log_array_dtag, 10, 2 );
