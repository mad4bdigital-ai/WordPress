<?php
/**
 * Plugin Name: Dynamic Filter SEO Bridge
 * Description: Governed profile-driven bridge between JetSmartFilters filter URLs, WordPress taxonomies, WPML terms, Elementor rendering, and Rank Math metadata.
 * Version: 0.4.0-alpha.14-local.1
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Author: Plugin Maintainers
 * Text Domain: dynamic-filter-seo-bridge
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'DFSB_VERSION', '0.4.0-alpha.14-local.1' );
define( 'DFSB_DIR', plugin_dir_path( __FILE__ ) );
define( 'DFSB_MAIN_FILE', __FILE__ );
define( 'DFSB_BOOT_BUILD', 'alpha14-local-verified-source-1' );
define( 'DFSB_ASSET_VERSION', '0.4.0-alpha.14-local.1' );
define( 'DFSB_RUNTIME_REVISION', 'context-rest-repair-1' );

// Public neutral API. Legacy symbols are read-only compatibility aliases for
// existing WordPress meta, control-plane adapters and signed releases.
// Do not remove these aliases until all registered consumers have migrated.
foreach ( array(
    'ETG_DFSB_VERSION' => DFSB_VERSION,
    'ETG_DFSB_DIR' => DFSB_DIR,
    'ETG_DFSB_MAIN_FILE' => DFSB_MAIN_FILE,
    'ETG_DFSB_BOOT_BUILD' => DFSB_BOOT_BUILD,
    'ETG_DFSB_ASSET_VERSION' => DFSB_ASSET_VERSION,
    'ETG_DFSB_RUNTIME_REVISION' => DFSB_RUNTIME_REVISION,
) as $legacy_symbol => $compat_value ) {
    if ( ! defined( $legacy_symbol ) ) { define( $legacy_symbol, $compat_value ); }
}


// Observable, non-authorizing runtime revision plus packaged source identity.
// The runtime revision describes behavior; exact Git/tree identity comes only from
// the deterministic build-identity.json embedded by the governed package builder.
// No public site-branded provenance metadata in the generic edition.
// Embedded release receipts remain available through governed diagnostics.


// Neutral namespace is canonical. Resolve legacy class names through a
// narrow alias rather than keeping a second implementation in memory.
spl_autoload_register( static function ( $class ) {
    $neutral_prefix = 'DynamicFilterSEOBridge\\';
    $legacy_prefix = 'ETG\\DynamicFilterSEOBridge\\';
    $is_legacy = 0 === strpos( $class, $legacy_prefix );
    if ( 0 !== strpos( $class, $neutral_prefix ) && ! $is_legacy ) { return; }
    $relative = substr( $class, strlen( $is_legacy ? $legacy_prefix : $neutral_prefix ) );
    $new_class = $neutral_prefix . $relative;
    $file = DFSB_DIR . 'includes/' . str_replace( '\\', '/', $relative ) . '.php';
    if ( is_readable( $file ) ) { require_once $file; }
    if ( $is_legacy && class_exists( $new_class, false ) && ! class_exists( $class, false ) ) {
        class_alias( $new_class, $class );
    }
} );

require_once DFSB_DIR . 'includes/Presentation/functions.php';
require_once DFSB_DIR . 'includes/Runtime/LegacyOptionMirror.php';
DynamicFilterSEOBridge\Runtime\LegacyOptionMirror::register();

// Shortcodes are registered explicitly with dual names in Elementor/Shortcodes.php.
// Existing serialized Elementor content remains valid via legacy aliases.

// Keep orphaned managed rewrite rules fail-closed even during Safe Boot hold.
// These guards perform no activation, query execution or persistent writes.
DynamicFilterSEOBridge\IndexFlow\ManagedRoutesRuntime::registerSafetyGuards();
register_deactivation_hook(__FILE__, static function () {
    // Explicit WordPress plugin deactivation invalidates the derived rewrite
    // cache; the next request rebuilds it from the remaining active plugins.
    delete_option('rewrite_rules');
});

// Installed packages key Safe Boot to their exact embedded source identity. The
// legacy label remains only as a deterministic source/dev fallback when no
// build-identity.json exists.
DynamicFilterSEOBridge\Runtime\BootGuard::register(
    DynamicFilterSEOBridge\Diagnostics\BuildIdentity::bootBuild( DFSB_BOOT_BUILD )
);
register_activation_hook( __FILE__, static function () {
    // New or replaced packages start inert. An administrator explicitly opts into
    // the guarded full boot after wp-admin has proven it can load safely.
    DynamicFilterSEOBridge\Runtime\BootGuard::holdOnFirstLoad( 'activation' );
} );

add_action( 'plugins_loaded', static function () {
    $guard = 'DynamicFilterSEOBridge\\Runtime\\BootGuard';
    if ( $guard::shouldHold() ) { return; }
    $guard::run( static function () {
        DynamicFilterSEOBridge\Bootstrap::instance()->boot();
    } );
}, 20 );

// Rank Math remains the sole SEO publisher. These read-only native-first
// gap filters only register after a certified bootstrap has passed Safe Boot.
// JetSmartFilters publication policy, canonical and robots are NOT changed.
add_action('plugins_loaded', static function (): void {
    if (!is_admin() && class_exists('RankMath')) {
        DynamicFilterSEOBridge\RankMath\SeoGapCoverageAdapter::register();
    }
}, 25);

// Portable native Elementor gallery URLs are always resolved from local
// attachment IDs (even during Safe Boot), never from an old tenant domain.
add_action('plugins_loaded', static function (): void {
    (new DynamicFilterSEOBridge\Presentation\PortableMediaGallery())->register();
}, 18);

// Minimal read-only admin access must survive an unverified release hold.
// Deliberately does not call Bootstrap or any provider/publisher adapter.
add_action('plugins_loaded', static function (): void {
    if (is_admin()) { (new DynamicFilterSEOBridge\Admin\SafeWorkflowPage())->register(); }
}, 19);

// Dynamic registered CPT, taxonomy and provider diagnostics remain read-only
// during Safe Boot and cannot authorize SEO or commercial mutations.
add_action('plugins_loaded', static function (): void {
    if (is_admin()) { (new DynamicFilterSEOBridge\Admin\SafeContextAuditPage())->register(); }
}, 19);

// New safe Dynamic Tag is registered for authorized Elementor admin previews
// even during Safe Boot; public output stays blocked until certification.
add_action('plugins_loaded', static function (): void {
    (new DynamicFilterSEOBridge\Elementor\ContextTagRegistration())->registerHooks();
}, 19);

// Read-only authenticated REST previews always remain available under Safe
// Boot. Never registers any public SEO/presentation mutation capability.
add_action('plugins_loaded', static function (): void {
    (new DynamicFilterSEOBridge\REST\ContextPreviewController())->register();
}, 19);

// MAD4B SCP owns issue history, deduplication and optional Fluent Boards
// tasks. DFSB only supplies source-backed findings on an explicit scan.
add_action('plugins_loaded', static function (): void {
    (new DynamicFilterSEOBridge\Integration\GapAlertSource())->register();
}, 19);

// Register shortcode names during Safe Boot too, so Elementor Shortcode
// widgets never leak raw bracketed tokens while publication is held.
// Their handlers explicitly refuse all public output under Safe Boot.
add_action('plugins_loaded', static function (): void {
    add_action('init',array(new DynamicFilterSEOBridge\Elementor\ContextPresentationShortcodes(),'register'),23);
}, 19);

// Site-specific media source configuration is independent of SEO boot.
// It is an explicitly authorized admin-only action with nonce protection.
add_action('plugins_loaded', static function (): void {
    if (is_admin()) { (new DynamicFilterSEOBridge\Admin\MediaSourcesSettingsPage())->register(); }
}, 19);

// Register a bounded ETG evidence provider for a central MAD4B MCP / Control
// Plane. ETG owns only the domain evidence projection; WordPress/Core/MCP own
// discovery, authentication, transport, pagination cursors, export and
// materialization. The abilities bridge exports only read-only calls into this
// same canonical provider instance.
add_action( 'plugins_loaded', static function () {
    $guard = 'DynamicFilterSEOBridge\\Runtime\\BootGuard';
    if ( $guard::shouldHold() ) { return; }

    $config = new DynamicFilterSEOBridge\Config\Configuration();
    $profiles = new DynamicFilterSEOBridge\Config\ProfileRegistry( $config );
    $topology = new DynamicFilterSEOBridge\Runtime\RuntimeTopologyDiscoverer();
    $inventory = new DynamicFilterSEOBridge\Diagnostics\RuntimeInventory(
        null,
        null,
        static function () use ( $topology ): array { return $topology->discover( true, false ); }
    );
    $reconciler = new DynamicFilterSEOBridge\Diagnostics\InventoryReconciler();
    $provider = new DynamicFilterSEOBridge\Diagnostics\EvidenceProvider(
        static function () use ( $inventory ): array { return $inventory->collect(); },
        static function ( array $snapshot, array $surfaceProfiles ) use ( $reconciler ): array {
            return $reconciler->analyze( $snapshot, $surfaceProfiles );
        },
        static function () use ( $profiles ): array { return $profiles->all(); }
    );
    $provider->register();
    ( new DynamicFilterSEOBridge\Integration\EvidenceAbilities( $provider ) )->register();
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
    $guard = 'DynamicFilterSEOBridge\\Runtime\\BootGuard';
    if ( $guard::shouldHold() || ! function_exists( 'apply_filters' ) ) { return; }
    $semantic = apply_filters( 'etg_dfsb_live_acceptance_provider', null );
    if ( ! $semantic instanceof DynamicFilterSEOBridge\Acceptance\LiveAcceptanceProvider ) { return; }
    $browser = new DynamicFilterSEOBridge\Acceptance\BrowserAcceptanceProvider(
        $semantic,
        static function (): array { return DynamicFilterSEOBridge\Diagnostics\BuildIdentity::collect(); }
    );
    $browser->register();
    DynamicFilterSEOBridge\Acceptance\BrowserObserverAsset::register();
    DynamicFilterSEOBridge\Acceptance\BrowserAcceptanceFreshnessGuard::register();
}, 22 );
