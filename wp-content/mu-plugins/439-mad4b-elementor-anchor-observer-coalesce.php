<?php
/**
 * Plugin Name: MAD4B Elementor Anchor Observer Coalesce (Pinned)
 * Description: Tour-only, source-hash-pinned, fail-closed Elementor anchor observer compatibility.
 * Version: 1.0.0
 *
 * This mu-plugin does NOT edit Elementor, bookings, form state, pricing or
 * authenticated cache boundaries. It only substitutes the exact verified
 * Elementor frontend JS asset on public tour-detail renderings.
 *
 * Regenerate the content-addressed JS using the separately tested first-party
 * build tool when and ONLY when the vendor source matches the pinned SHA.
 */
if ( ! defined( 'ABSPATH' ) ) {
    return;
}

add_filter(
    'script_loader_src',
    static function ( $src, $handle ) {
        if ( 'elementor-frontend' !== $handle || ! is_string( $src ) ) {
            return $src;
        }
        if ( is_admin() || ! is_singular( 'tours-and-activities' ) || ( function_exists( 'is_preview' ) && is_preview() ) || isset( $_GET['elementor-preview'] ) ) {
            return $src;
        }
        if ( false === strpos( $src, '/elementor/assets/js/frontend.min.js' ) ) {
            return $src;
        }

        // The original vendor module must be an EXACT independently inspected
        // binary. Elementor upgrades fail closed to the original vendor URL.
        $original = WP_PLUGIN_DIR . '/elementor/assets/js/frontend.min.js';
        $asset_name = 'mad4b-elementor-anchor-coalesced-bbfe6403.js';
        $asset = WP_CONTENT_DIR . '/mu-plugins/assets/' . $asset_name;
        static $asset_valid = null;
        if ( null === $asset_valid ) {
            $asset_valid = is_file( $original ) && is_file( $asset )
                && hash_equals( 'bbfe6403b4ddba29284c72d20f000154d04588d60850fc39a6fd65861c6d83e6', hash_file( 'sha256', $original ) )
                && hash_equals( 'a2ff13fb4b2d624179d1304fe7fc02771e512c157f624351d5f2d4dbed7a578c', hash_file( 'sha256', $asset ) );
        }
        if ( ! $asset_valid ) {
            return $src;
        }
        return content_url( '/mu-plugins/assets/' . $asset_name );
    },
    99,
    2
);
