<?php
/**
 * Local first-view resource hint using the native WordPress preload API.
 * The tour archive has a mobile-only Elementor background as its LCP.
 * Preload it at high priority before the linked stylesheets are parsed.
 * Non-mobile users do not fetch this preload thanks to the media query.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'wp_preload_resources', static function ( $resources ) {
	if ( is_admin() ) {
		return $resources;
	}
	$path = (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH );
	if ( '/tours-and-activities/' !== $path ) {
		return $resources;
	}
	$path_in_uploads = 'uploads/2025/11/banner-search.jpg.webp';
	$absolute_file = WP_CONTENT_DIR . '/' . $path_in_uploads;
	if ( ! is_readable( $absolute_file ) ) {
		return $resources;
	}
	$href = content_url( $path_in_uploads );
	foreach ( $resources as $resource ) {
		if ( isset( $resource['href'] ) && $href === $resource['href'] ) {
			return $resources;
		}
	}
	$resources[] = array(
		'href'          => $href,
		'as'            => 'image',
		'media'         => '(max-width: 767px)',
		'fetchpriority' => 'high',
	);
	return $resources;
}, 10 );
