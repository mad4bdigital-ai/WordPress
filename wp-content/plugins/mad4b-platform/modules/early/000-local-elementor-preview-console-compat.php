<?php
/**
 * Plugin Name: Local QA Elementor Preview Console Compatibility
 * Description: Disable unrelated Rank Math frontend analytics and request
 * async Google API loading only in two map-free Elementor Listing previews.
 */
defined( 'ABSPATH' ) || exit;

if (
    ! function_exists( 'wp_get_environment_type' )
    || wp_get_environment_type() !== 'local'
    || ! isset( $_GET['elementor-preview'] )
    || ! in_array( absint( $_GET['elementor-preview'] ), [ 40299, 45152 ], true )
    || ( isset( $_SERVER['REQUEST_METHOD'] ) && ! in_array( $_SERVER['REQUEST_METHOD'], [ 'GET', 'HEAD' ], true ) )
) {
    return;
}

// The Rank Math admin-bar analytics widget cannot resolve a frontend post ID
// from an Elementor Listing preview and calls /rankmath/v1/an/post/undefined.
// Keep Rank Math's actual SEO/editor features untouched.
add_filter( 'rank_math/analytics/frontend_stats', '__return_false', 1 );

// Google recommends loading=async in API URLs. Retain JetEngine's original
// callback (and its own provider lifecycle) rather than replacing it.
add_filter( 'jet-engine/maps-listing/map-providers/google/api-url/query-args',
    static function ( $args ) {
        if ( is_array( $args ) ) {
            $args['loading'] = 'async';
        }
        return $args;
    }, 20 );
