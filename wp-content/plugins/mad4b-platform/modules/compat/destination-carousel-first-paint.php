<?php
/**
 * Plugin Name: Local JetEngine Destination Carousel First Paint
 * Description: Match the first-render destination listing grid to its Swiper breakpoints without changing the template or slide behavior.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_enqueue_scripts', static function () {
	if ( is_admin() || ! is_front_page() || ! class_exists( '\Elementor\Plugin', false ) ) {
		return;
	}
	$css_path = WPMU_PLUGIN_DIR . '/assets/destination-carousel-first-paint.css';
	if ( is_readable( $css_path ) ) {
		wp_enqueue_style(
			'destination-carousel-first-paint',
			content_url( 'mu-plugins/assets/destination-carousel-first-paint.css' ),
			array(),
			(string) filemtime( $css_path )
		);
	}
}, 12 );
