<?php
/**
 * Plugin Name: ETG Dynamic Filter SEO Bridge
 * Description: Governed profile-driven bridge between JetSmartFilters filter URLs, WordPress taxonomies, WPML terms, Elementor rendering, and Rank Math metadata.
 * Version: 0.4.0-alpha.13
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Author: MAD4B
 * Text Domain: etg-dynamic-filter-seo-bridge
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'ETG_DFSB_VERSION', '0.4.0-alpha.13' );
define( 'ETG_DFSB_DIR', plugin_dir_path( __FILE__ ) );
define( 'ETG_DFSB_BOOT_BUILD', 'alpha13-container-background-4' );
define( 'ETG_DFSB_ASSET_VERSION', '0.4.0-alpha.13-build4' );
define( 'ETG_DFSB_RUNTIME_REVISION', 'archive-hero-render-5' );

// Observable, non-authorizing build identity. This distinguishes exact alpha builds
// that intentionally share the same semantic plugin version during certification.
add_action( 'wp_head', static function () {
    if ( ! function_exists( 'esc_attr' ) ) { return; }
    echo '<meta name="etg-dfsb-runtime-build" content="' . esc_attr( ETG_DFSB_RUNTIME_REVISION ) . '" />' . "\n";
}, 1 );

spl_autoload_register(static function ( $class ) {
    $prefix = 'ETG\\DynamicFilterSEOBridge\\';
    if ( 0 !== strpos( $class, $prefix ) ) { return; }
    $relative = substr( $class, strlen( $prefix ) );
    $file = ETG_DFSB_DIR . 'includes/' . str_replace( '\\', '/', $relative ) . '.php';
    if ( is_readable( $file ) ) { require_once $file; }
});

require_once ETG_DFSB_DIR . 'includes/Presentation/functions.php';

ETG\DynamicFilterSEOBridge\Runtime\BootGuard::register( ETG_DFSB_BOOT_BUILD );
register_activation_hook( __FILE__, static function () {
    // New or replaced packages start inert. An administrator explicitly opts into
    // the guarded full boot after wp-admin has proven it can load safely.
    ETG\DynamicFilterSEOBridge\Runtime\BootGuard::holdOnFirstLoad( 'activation' );
} );

add_action( 'plugins_loaded', static function () {
    $guard = 'ETG\\DynamicFilterSEOBridge\\Runtime\\BootGuard';
    if ( $guard::shouldHold() ) { return; }
    $guard::run( static function () {
        ETG\DynamicFilterSEOBridge\Bootstrap::instance()->boot();
    } );
}, 20 );
