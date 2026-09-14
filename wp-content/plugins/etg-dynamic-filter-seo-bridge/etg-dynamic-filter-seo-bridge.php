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

// Observable, non-authorizing runtime revision plus packaged source identity.
// The runtime revision describes behavior; exact Git/tree identity comes only from
// the deterministic build-identity.json embedded by the governed package builder.
add_action( 'wp_head', static function () {
    if ( ! function_exists( 'esc_attr' ) ) { return; }
    // Preserve the Alpha13 public provenance ABI only for installations that
    // were positively classified as legacy ETG. Fresh/generic IndexFlow
    // installations must not inherit an ETG fingerprint merely by activating
    // the package.
    $config = new ETG\DynamicFilterSEOBridge\Config\Configuration();
    if ( 'alpha13' !== (string) $config->get( 'compatibility_profile', '' ) ) { return; }
    echo '<meta name="etg-dfsb-runtime-build" content="' . esc_attr( ETG_DFSB_RUNTIME_REVISION ) . '" />' . "\n";
    $identity = ETG\DynamicFilterSEOBridge\Diagnostics\BuildIdentity::collect();
    if ( ! empty( $identity['valid'] ) ) {
        echo '<meta name="etg-dfsb-source-sha" content="' . esc_attr( (string) $identity['git_sha'] ) . '" />' . "\n";
        echo '<meta name="etg-dfsb-source-tree" content="' . esc_attr( (string) $identity['tree_sha'] ) . '" />' . "\n";
    }
}, 1 );

spl_autoload_register(static function ( $class ) {
    $prefix = 'ETG\\DynamicFilterSEOBridge\\';
    if ( 0 !== strpos( $class, $prefix ) ) { return; }
    $relative = substr( $class, strlen( $prefix ) );
    $file = ETG_DFSB_DIR . 'includes/' . str_replace( '\\', '/', $relative ) . '.php';
    if ( is_readable( $file ) ) { require_once $file; }
});

require_once ETG_DFSB_DIR . 'includes/Presentation/functions.php';

// Installed packages key Safe Boot to their exact embedded source identity. The
// legacy label remains only as a deterministic source/dev fallback when no
// build-identity.json exists.
ETG\DynamicFilterSEOBridge\Runtime\BootGuard::register(
    ETG\DynamicFilterSEOBridge\Diagnostics\BuildIdentity::bootBuild( ETG_DFSB_BOOT_BUILD )
);
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

// Register a bounded ETG evidence provider for a central MAD4B MCP / Control
// Plane. ETG owns only the domain evidence projection; WordPress/Core/MCP own
// discovery, authentication, transport, pagination cursors, export and
// materialization. The abilities bridge exports only read-only calls into this
// same canonical provider instance.
add_action( 'plugins_loaded', static function () {
    $guard = 'ETG\\DynamicFilterSEOBridge\\Runtime\\BootGuard';
    if ( $guard::shouldHold() ) { return; }

    $config = new ETG\DynamicFilterSEOBridge\Config\Configuration();
    $profiles = new ETG\DynamicFilterSEOBridge\Config\ProfileRegistry( $config );
    $topology = new ETG\DynamicFilterSEOBridge\Runtime\RuntimeTopologyDiscoverer();
    $inventory = new ETG\DynamicFilterSEOBridge\Diagnostics\RuntimeInventory(
        null,
        null,
        static function () use ( $topology ): array { return $topology->discover( true, false ); }
    );
    $reconciler = new ETG\DynamicFilterSEOBridge\Diagnostics\InventoryReconciler();
    $provider = new ETG\DynamicFilterSEOBridge\Diagnostics\EvidenceProvider(
        static function () use ( $inventory ): array { return $inventory->collect(); },
        static function ( array $snapshot, array $surfaceProfiles ) use ( $reconciler ): array {
            return $reconciler->analyze( $snapshot, $surfaceProfiles );
        },
        static function () use ( $profiles ): array { return $profiles->all(); }
    );
    $provider->register();
    ( new ETG\DynamicFilterSEOBridge\Integration\EvidenceAbilities( $provider ) )->register();
}, 21 );

// Browser Runtime Acceptance remains an external-browser execution layer. ETG
// exposes only a governed, non-authorizing provider: it derives a signed plan
// from the already-verified semantic provider and reduces externally observed
// browser evidence. No browser engine, arbitrary JavaScript, new public REST
// transport, profile mutation, SEO publication, or Production activation is
// introduced here. The observer script is not auto-enqueued for normal visitors;
// its exact same-origin URL/hash are projected through the provider capabilities
// so an external browser agent can load only the package-owned bounded observer.
// A separate stateless freshness decorator adds a short-lived signed challenge
// to ready browser plans and rejects stale/replayed observed evidence without
// writing options, transients, DB rows, or any other persistent state.
add_action( 'plugins_loaded', static function () {
    $guard = 'ETG\\DynamicFilterSEOBridge\\Runtime\\BootGuard';
    if ( $guard::shouldHold() || ! function_exists( 'apply_filters' ) ) { return; }
    $semantic = apply_filters( 'etg_dfsb_live_acceptance_provider', null );
    if ( ! $semantic instanceof ETG\DynamicFilterSEOBridge\Acceptance\LiveAcceptanceProvider ) { return; }
    $browser = new ETG\DynamicFilterSEOBridge\Acceptance\BrowserAcceptanceProvider(
        $semantic,
        static function (): array { return ETG\DynamicFilterSEOBridge\Diagnostics\BuildIdentity::collect(); }
    );
    $browser->register();
    ETG\DynamicFilterSEOBridge\Acceptance\BrowserObserverAsset::register();
    ETG\DynamicFilterSEOBridge\Acceptance\BrowserAcceptanceFreshnessGuard::register();
}, 22 );
