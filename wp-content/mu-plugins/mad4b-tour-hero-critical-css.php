<?php
/**
 * Plugin Name: MAD4B Tour Hero Critical Styles
 * Description: Enqueues existing Elementor hero and leading-section CSS in the head before first paint.
 * Version: 1.0.0
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
add_action( 'wp_enqueue_scripts', static function () {
    if ( ! is_singular( 'tours-and-activities' ) ) { return; }
    $uploads = wp_get_upload_dir();
    if ( ! empty( $uploads['error'] ) ) { return; }
    $ids = array( 24780, 45262 );
    foreach ( $ids as $source_id ) {
        // WPML can resolve 24780 to 33711 on Spanish pages; 45262 is shared.
        $id = (int) apply_filters( 'wpml_object_id', $source_id, 'elementor_library', true );
        if ( ! $id ) { continue; }
        $rel = '/elementor/css/post-' . $id . '.css';
        if ( ! is_file( $uploads['basedir'] . $rel ) ) { continue; }
        // Reuse Elementor's native handle: no second link is printed by a nested template.
        wp_enqueue_style( 'elementor-post-' . $id, $uploads['baseurl'] . $rel, array(), null, 'all' );
    }
}, 5 );
