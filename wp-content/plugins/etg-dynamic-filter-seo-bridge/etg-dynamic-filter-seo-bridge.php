<?php
/**
 * Plugin Name: ETG Dynamic Filter SEO Bridge
 * Description: Governed profile-driven bridge between JetSmartFilters filter URLs, WordPress taxonomies, WPML terms, Elementor rendering, and Rank Math metadata.
 * Version: 0.4.0-alpha.5
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Author: MAD4B
 * Text Domain: etg-dynamic-filter-seo-bridge
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'ETG_DFSB_VERSION', '0.4.0-alpha.5' );
define( 'ETG_DFSB_DIR', plugin_dir_path( __FILE__ ) );

spl_autoload_register(
	static function ( $class ) {
		$prefix = 'ETG\\DynamicFilterSEOBridge\\';
		if ( 0 !== strpos( $class, $prefix ) ) { return; }
		$relative = substr( $class, strlen( $prefix ) );
		$file = ETG_DFSB_DIR . 'includes/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_readable( $file ) ) { require_once $file; }
	}
);

add_action(
	'plugins_loaded',
	static function () { ETG\DynamicFilterSEOBridge\Bootstrap::instance()->boot(); },
	20
);
