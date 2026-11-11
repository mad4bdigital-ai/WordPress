<?php
/**
 * Plugin Name: MAD4B WPML Contextual Translation Batch
 * Description: Learns public translation ID groups per page and primes WPML's own request cache in batches.
 * Version: 1.1.0
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
add_action( 'wp', static function () {
    if ( ! wp_using_ext_object_cache() || is_admin() || wp_doing_ajax() || is_user_logged_in()
        || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'WP_CLI' ) && WP_CLI )
        || ! is_singular() || is_preview()
        || get_option( 'mad4b_wpml_translation_batch', 'auto' ) === 'off' ) { return; }
    $post = get_queried_object();
    if ( ! ( $post instanceof WP_Post ) ) { return; }
    $type = get_post_type_object( $post->post_type );
    if ( ! $type || ! $type->public || preg_match( '/(?:orders?|bookings?|quotes?|checkout|payments?|customers?|invoices?|sessions?|reservations?)/i', $post->post_type ) ) { return; }
    foreach ( array_keys( (array) $_GET ) as $param ) {
        if ( preg_match( '/^(?:utm_[a-z0-9_]+|gclid|fbclid|msclkid)$/i', (string) $param ) ) { continue; }
        return;
    }
    foreach ( array_keys( (array) $_COOKIE ) as $cookie ) {
        if ( preg_match( '/(?:market|country|passport|currency|booking|checkout|order|cart|customer|session|woocommerce|token|nonce|jet_smart_filters)/i', (string) $cookie ) ) { return; }
    }
    global $wpml_post_translations, $wpml_term_translations;
    if ( ! is_object( $wpml_post_translations ) || ! method_exists( $wpml_post_translations, 'prefetch_ids' ) ) { return; }
    $lang = (string) apply_filters( 'wpml_current_language', null );
    if ( '' === $lang ) { $lang = determine_locale(); }
    // Roster contains only IDs to prefetch, not translated results; it can outlive unrelated content changes.
    $key = 'context-' . substr( hash( 'sha256', home_url('/') . '|' . get_current_blog_id() . '|' . $post->post_type . '|' . $post->ID . '|' . $lang ), 0, 40 );
    $group = 'mad4b-wpml-context';
    $prior = wp_cache_get( $key, $group );
    $prior = is_array( $prior ) ? $prior : [];
    $state = [ 'posts' => [], 'terms' => [] ];
    foreach ( [ 'posts', 'terms' ] as $kind ) {
        $ids = array_slice( array_values( array_unique( array_filter( array_map( 'absint', (array) ( $prior[ $kind ] ?? [] ) ) ) ) ), 0, 700 );
        $translator = 'posts' === $kind ? $wpml_post_translations : $wpml_term_translations;
        if ( ! $ids || ! is_object( $translator ) || ! method_exists( $translator, 'prefetch_ids' ) ) { continue; }
        foreach ( array_chunk( $ids, 150 ) as $chunk ) { $translator->prefetch_ids( $chunk ); }
    }
    add_filter( 'query', static function ( $sql ) use ( &$state ) {
        if ( ! str_contains( $sql, 'wpml_translations.translation_id' ) || ! str_contains( $sql, 'tridt.element_id IN(' ) ) { return $sql; }
        $kind = str_contains( $sql, 'JOIN wp_term_taxonomy' ) ? 'terms' : ( str_contains( $sql, 'JOIN wp_posts' ) ? 'posts' : '' );
        if ( ! $kind || ! preg_match( '/tridt\.element_id\s+IN\s*\(([^)]+)\)/i', $sql, $match ) ) { return $sql; }
        foreach ( explode( ',', $match[1] ) as $raw ) {
            $raw = trim( $raw );
            if ( ctype_digit( $raw ) && count( $state[ $kind ] ) < 700 ) { $state[ $kind ][ (int) $raw ] = true; }
        }
        return $sql;
    }, 50 );
    add_action( 'shutdown', static function () use ( &$state, $prior, $key, $group ) {
        if ( http_response_code() !== 200 ) { return; }
        $save = [];
        foreach ( [ 'posts', 'terms' ] as $kind ) {
            $save[ $kind ] = array_slice( array_values( array_unique( array_merge(
                array_map( 'absint', (array) ( $prior[ $kind ] ?? [] ) ), array_keys( $state[ $kind ] )
            ) ) ), 0, 700 );
        }
        if ( ! empty( $save['posts'] ) || ! empty( $save['terms'] ) ) {
            wp_cache_set( $key, $save, $group, 900 );
        }
    }, PHP_INT_MAX );
}, 1 );
