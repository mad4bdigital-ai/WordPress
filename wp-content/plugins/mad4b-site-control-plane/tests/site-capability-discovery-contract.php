<?php
/**
 * Standalone read-only site discovery contract. No real site writes or browser.
 */
define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['site_plugins'] = array( 'etg-dynamic-filter-seo-bridge/etg-dynamic-filter-seo-bridge.php', 'unknown-widget/main.php' );
$GLOBALS['site_network_plugins'] = array();
$GLOBALS['site_post_types'] = array( 'post', 'page', 'tour' );
$GLOBALS['site_taxonomies'] = array( 'category', 'tour_type' );
function get_option( $name, $fallback = array() ) { return 'active_plugins' === $name ? $GLOBALS['site_plugins'] : $fallback; }
function get_site_option( $name, $fallback = array() ) { return 'active_sitewide_plugins' === $name ? $GLOBALS['site_network_plugins'] : $fallback; }
function get_post_types( $args = array(), $output = 'names' ) { return $GLOBALS['site_post_types']; }
function get_taxonomies( $args = array(), $output = 'names' ) { return $GLOBALS['site_taxonomies']; }
function wp_json_encode( $input ) { return json_encode( $input ); }
require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-site-capability-discovery.php';
function expect_site( $ok, $message ) {
	if ( ! $ok ) { fwrite( STDERR, "FAIL: $message\n" ); exit( 1 ); }
}
$providers = array( 'etg-dfsb' => array(
	'descriptor' => array( 'recognition' => array( 'source_plugins' => array( 'etg-dynamic-filter-seo-bridge' ) ) ),
) );
$c = 'MAD4B_SCP_Site_Capability_Discovery';
$observed = $c::observe( 'https://staging.egypttourgates.com', $providers );
expect_site( $observed['discovery_complete'] === true, 'complete bounded inventory' );
expect_site( $observed['certification_issued'] === false && $observed['authorizing'] === false, 'discovery cannot authorize or certify' );
expect_site( $observed['provider_matches'][0]['recognized'] === true, 'provider declarative plugin signal matched' );
expect_site( $observed['unmapped_plugins'] === array( 'unknown-widget' ), 'unknown plugin retained as unmapped, not guessed' );
expect_site( in_array( 'tour', $observed['post_types'], true ) && in_array( 'tour_type', $observed['taxonomies'], true ), 'runtime post types and taxonomies observed' );
expect_site( strlen( $observed['snapshot_sha256'] ) === 64, 'fingerprint' );
$unmapped = $c::observe( 'https://staging.allroyalegypt.com', array() );
expect_site( count( $unmapped['unmapped_plugins'] ) === 2 && count( $unmapped['provider_matches'] ) === 0, 'no arbitrary provider selected by hostname' );
$GLOBALS['site_plugins'][] = 'third-addon/bootstrap.php';
$changed = $c::observe( 'https://staging.egypttourgates.com', $providers );
expect_site( $changed['snapshot_sha256'] !== $observed['snapshot_sha256'], 'inventory change invalidates snapshot' );
$GLOBALS['site_plugins'] = array( 'unsafe/path/traversal.php' );
$invalid = $c::observe( 'https://staging.egypttourgates.com', $providers );
expect_site( $invalid['discovery_complete'] === false && in_array( 'plugin_basename_invalid', $invalid['blocking_reasons'], true ), 'unsafe plugin basename denied' );
$GLOBALS['site_plugins'] = array_fill( 0, 129, 'repeated/main.php' );
$over = $c::observe( 'https://staging.egypttourgates.com', $providers );
expect_site( $over['discovery_complete'] === false && in_array( 'plugin_inventory_invalid_or_overflow', $over['blocking_reasons'], true ), 'oversized inventory cannot silently truncate' );
$GLOBALS['site_plugins'] = array( 'etg-dynamic-filter-seo-bridge/main.php' );
$GLOBALS['site_network_plugins'] = array( 'network-addon/main.php' => 10 );
$network = $c::observe( 'https://staging.egypttourgates.com', $providers );
expect_site( in_array( 'network-addon', $network['plugins'], true ), 'multisite network plugin observation' );
$GLOBALS['site_network_plugins'] = array();
$GLOBALS['site_taxonomies'] = array_fill( 0, 97, 'large' );
$tax = $c::observe( 'https://staging.egypttourgates.com', $providers );
expect_site( $tax['discovery_complete'] === false && in_array( 'taxonomy_inventory_unavailable_or_invalid', $tax['blocking_reasons'], true ), 'taxonomy budget denied' );
$GLOBALS['site_taxonomies'] = array( 'category' );
$origin = $c::observe( 'http://localhost:8080', $providers );
expect_site( $origin['discovery_complete'] === false && in_array( 'site_origin_invalid', $origin['blocking_reasons'], true ), 'non-TLS origin denied' );

echo "MAD4B_SITE_CAPABILITY_DISCOVERY: PASS\n";
