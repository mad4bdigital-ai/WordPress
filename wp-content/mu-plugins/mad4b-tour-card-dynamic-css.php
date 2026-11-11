<?php
/**
 * Plugin Name: MAD4B Tour Card Dynamic CSS
 * Description: Avoid CSS parsing for content-only dynamic fields in homepage tour cards.
 */
defined( 'ABSPATH' ) || exit;

final class MAD4B_Tour_Card_Dynamic_CSS {
    private static function content_only_ids( $nodes ) {
        $fields = [
            'heading' => [ 'title', 'link' ],
            'jet-listing-dynamic-field' => [ 'field_fallback' ],
            'jet-listing-dynamic-link' => [ 'aria_label_attr' ],
        ];
        $ids = [];
        foreach ( $nodes as $node ) {
            $type = $node['widgetType'] ?? '';
            $dynamic = $node['settings']['__dynamic__'] ?? [];
            if ( isset( $fields[ $type ] ) && is_array( $dynamic ) && $dynamic
                && ! array_diff( array_keys( $dynamic ), $fields[ $type ] ) ) {
                $ids[] = $node['id'];
            }
            if ( ! empty( $node['elements'] ) ) {
                $ids = array_merge( $ids, self::content_only_ids( $node['elements'] ) );
            }
        }
        return $ids;
    }

    public static function wrap( $callback ) {
        return static function ( $html, $id, $inner ) use ( $callback ) {
            static $plans = [];
            if ( ! in_array( (int) $id, [ 33747, 33753 ], true ) ) {
                return $callback( $html, $id, $inner );
            }
            if ( ! isset( $plans[ $id ] ) ) {
                $raw = get_post_meta( $id, '_elementor_data', true );
                $nodes = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
                $plans[ $id ] = is_array( $nodes ) ? self::content_only_ids( $nodes ) : [];
            }
            $meta = get_post_meta( $id, '_elementor_css', true );
            if ( ! $plans[ $id ] || ! is_array( $meta['dynamic_elements_ids'] ?? null ) ) {
                return $callback( $html, $id, $inner );
            }
            $meta['dynamic_elements_ids'] = array_values( array_diff( $meta['dynamic_elements_ids'], $plans[ $id ] ) );
            $scope = static function ( $value, $post_id, $key, $single ) use ( $id, $meta ) {
                if ( null === $value && $single && (int) $post_id === (int) $id && '_elementor_css' === $key ) {
                    return [ $meta ];
                }
                return $value;
            };
            add_filter( 'get_post_metadata', $scope, PHP_INT_MAX, 4 );
            try {
                return $callback( $html, $id, $inner );
            } finally {
                remove_filter( 'get_post_metadata', $scope, PHP_INT_MAX );
            }
        };
    }
}

add_action( 'wp', static function () {
    if ( ! is_front_page() || is_admin() || is_user_logged_in() || is_preview() || is_customize_preview()
        || wp_doing_ajax() || ! empty( $_SERVER['HTTP_COOKIE'] ) || ! empty( $_SERVER['QUERY_STRING'] )
        || ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) || ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'GET'
        || ! in_array( apply_filters( 'wpml_current_language', '' ), [ 'en', 'es' ], true ) ) {
        return;
    }
    global $wp_filter;
    $hook = $wp_filter['jet-engine/elementor-views/frontend/listing-content'] ?? null;
    if ( ! $hook ) return;
    foreach ( $hook->callbacks as $priority => $entries ) {
        foreach ( $entries as $key => $entry ) {
            $fn = $entry['function'];
            if ( is_array( $fn ) && $fn[0] instanceof Jet_Engine_Dynamic_Tags_Manager
                && 'add_listing_item_dynamic_css' === $fn[1] ) {
                $hook->callbacks[ $priority ][ $key ]['function'] = MAD4B_Tour_Card_Dynamic_CSS::wrap( $fn );
                return;
            }
        }
    }
}, 100 );
