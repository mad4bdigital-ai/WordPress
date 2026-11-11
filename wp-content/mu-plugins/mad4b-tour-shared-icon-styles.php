<?php
/**
 * Plugin Name: MAD4B Shared Tour Icon Styles
 * Description: Share identical UAEL and HFE icon styles on public tour detail pages.
 */
defined( 'ABSPATH' ) || exit;

add_action( 'wp_print_styles', static function () {
    if ( ! is_singular( 'tours-and-activities' ) || is_preview() || is_customize_preview() ) {
        return;
    }
    $styles = wp_styles();
    $pairs = [
        'uael-teammember-social-icons'      => 'hfe-social-icons',
        'uael-social-share-icons-brands'    => 'hfe-social-share-icons-brands',
        'uael-social-share-icons-fontawesome' => 'hfe-social-share-icons-fontawesome',
        'uael-nav-menu-icons'               => 'hfe-nav-menu-icons',
    ];
    foreach ( $pairs as $first => $last ) {
        if ( ! wp_style_is( $first, 'enqueued' ) || ! wp_style_is( $last, 'enqueued' ) ) {
            continue;
        }
        $a = $styles->registered[ $first ] ?? null;
        $b = $styles->registered[ $last ] ?? null;
        if ( ! $a || ! $b || ! $a->src || $a->src !== $b->src || $a->args !== $b->args
            || $a->ver !== $b->ver || $a->extra || $b->extra || $a->deps || $b->deps ) {
            continue;
        }
        // Keep both handles registered and leave the later stylesheet in its original position.
        $a->src = false;
    }
}, PHP_INT_MAX );
