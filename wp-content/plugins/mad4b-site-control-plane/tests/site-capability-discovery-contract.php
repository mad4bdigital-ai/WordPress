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
define( 'WP_PLUGIN_DIR', __DIR__ . '/fixture-plugin-dir' );
$GLOBALS['site_plugin_versions'] = array();
function get_file_data( $file, $headers, $context = '' ) {
	$key = substr( $file, strlen( WP_PLUGIN_DIR ) + 1 );
	return array( 'Version' => array_key_exists( $key, $GLOBALS['site_plugin_versions'] )
		? $GLOBALS['site_plugin_versions'][ $key ] : '1.0.0' );
}
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
$cpt_provider = array( 'cpt-adapter' => array( 'descriptor' => array( 'recognition' => array(
  'source_post_types' => array( 'tour' ), 'source_taxonomies' => array( 'tour_type' )
) ) ) );
$cpt = $c::observe( 'https://staging.egypttourgates.com', $cpt_provider );
expect_site( $cpt['provider_matches'][0]['recognized'] === true &&
  $cpt['provider_matches'][0]['matched_post_types'] === 1 &&
  $cpt['provider_matches'][0]['matched_taxonomies'] === 1, 'plugins are not prerequisite for post-type/taxonomy adapter recognition' );
expect_site( $observed['unmapped_plugins'] === array( 'unknown-widget' ), 'unknown plugin retained as unmapped, not guessed' );
expect_site( in_array( 'tour', $observed['post_types'], true ) && in_array( 'tour_type', $observed['taxonomies'], true ), 'runtime post types and taxonomies observed' );
expect_site( strlen( $observed['snapshot_sha256'] ) === 64, 'fingerprint' );
expect_site( $observed['plugin_versions_complete'] === true && count( $observed['plugin_versions'] ) === 2, 'active plugin versions observed without activation' );
$GLOBALS['site_plugin_versions']['etg-dynamic-filter-seo-bridge/etg-dynamic-filter-seo-bridge.php'] = '2.0.0';
$upgrade = $c::observe( 'https://staging.egypttourgates.com', $providers );
expect_site( $upgrade['snapshot_sha256'] !== $observed['snapshot_sha256'], 'same slug with upgraded plugin invalidates snapshot' );
$GLOBALS['site_plugin_versions']['etg-dynamic-filter-seo-bridge/etg-dynamic-filter-seo-bridge.php'] = '';
$unverifiable = $c::observe( 'https://staging.egypttourgates.com', $providers );
expect_site( $unverifiable['discovery_complete'] === false &&
  in_array( 'plugin_version_evidence_unavailable', $unverifiable['blocking_reasons'], true ), 'unverified plugin version cannot certify discovery' );
$GLOBALS['site_plugin_versions'] = array();
$unmapped = $c::observe( 'https://staging.allroyalegypt.com', array() );
expect_site( count( $unmapped['unmapped_plugins'] ) === 2 && count( $unmapped['provider_matches'] ) === 0, 'no arbitrary provider selected by hostname' );
$GLOBALS['site_plugins'][] = 'third-addon/bootstrap.php';
$changed = $c::observe( 'https://staging.egypttourgates.com', $providers );
expect_site( $changed['snapshot_sha256'] !== $observed['snapshot_sha256'], 'inventory change invalidates snapshot' );
$GLOBALS['site_plugins'] = array( '../plugin.php' );
$traversal = $c::observe( 'https://staging.egypttourgates.com', $providers );
expect_site( ! $traversal['discovery_complete'] && in_array( 'plugin_basename_invalid', $traversal['blocking_reasons'], true ), 'relative plugin path denied before metadata read' );
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
$GLOBALS['site_network_plugins']['etg-dynamic-filter-seo-bridge/main.php'] = 11;
$duplicate_activation = $c::observe( 'https://staging.egypttourgates.com', $providers );
expect_site( $duplicate_activation['discovery_complete'] === true &&
  count( $duplicate_activation['plugin_versions'] ) === 2, 'same exact plugin activated both locally and network-wide is counted once' );
$GLOBALS['site_network_plugins'] = array();
$GLOBALS['site_taxonomies'] = array_fill( 0, 97, 'large' );
$tax = $c::observe( 'https://staging.egypttourgates.com', $providers );
expect_site( $tax['discovery_complete'] === false && in_array( 'taxonomy_inventory_unavailable_or_invalid', $tax['blocking_reasons'], true ), 'taxonomy budget denied' );
$GLOBALS['site_taxonomies'] = array( 'category' );
$origin = $c::observe( 'http://localhost:8080', $providers );
expect_site( $origin['discovery_complete'] === false && in_array( 'site_origin_invalid', $origin['blocking_reasons'], true ), 'non-TLS origin denied' );

echo "MAD4B_SITE_CAPABILITY_DISCOVERY: PASS\n";
