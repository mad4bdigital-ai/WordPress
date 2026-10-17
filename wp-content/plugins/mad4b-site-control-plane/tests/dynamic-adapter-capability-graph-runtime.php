<?php
/** Pure graph fixture: new vendor plugins require no PHP adapter hardcode. */
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );
function sanitize_text_field( $s ) { return (string) $s; }
function sanitize_key( $s ) { return strtolower( preg_replace( '/[^a-z0-9_-]/i', '', (string) $s ) ); }
function wp_json_encode( $v, $opts = 0 ) { return json_encode( $v, $opts ); }
function graph_ok( $x, $label ) { if ( ! $x ) { fwrite( STDERR, 'FAIL: ' . $label . "\n" ); exit( 1 ); } }
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-plugin-discovery.php';
$c = 'MAD4B_SCP_Plugin_Discovery';
$plugins = array(
    'new-vendor/main.php' => array( 'RequiresPlugins' => 'core-provider,missing-provider' ),
    'core-provider/main.php' => array(),
    'another/main.php' => array(),
);
$coverage = array( 'truncated' => false, 'plugins' => array(
    array( 'plugin_file' => 'new-vendor/main.php', 'version' => '4.5.9',
        'active' => true, 'family' => 'unknown', 'coverage_state' => 'adapter_required',
        'adapter_registered' => false, 'adapter_read_abilities' => array() ),
    array( 'plugin_file' => 'core-provider/main.php', 'version' => '1.0.0',
        'active' => true, 'adapter_registered' => true,
        'adapter_runtime_available' => true, 'adapter_id' => 'core-provider',
        'provider_certification_ok' => false,
        'adapter_read_abilities' => array( 'core-provider/read-status' ) ),
    array( 'plugin_file' => 'another/main.php', 'version' => '1.0.0',
        'active' => false, 'adapter_registered' => false, 'adapter_read_abilities' => array() ),
) );
$abilities = array( 'new-vendor/read-status', 'new-vendor/delete-all',
    'core-provider/read-status', 'another/status' );
$graph = $c::build_capability_graph( $coverage, $plugins, $abilities );
graph_ok( $graph['complete'] === true && $graph['installed_plugin_count'] === 3,
    'Unlimited vendors from installed inventory without vendor-specific code' );
$rows = array(); foreach ( $graph['items'] as $row ) $rows[ $row['slug'] ] = $row;
graph_ok( $rows['new-vendor']['unreviewed_namespace_candidates_count'] === 2
    && $rows['new-vendor']['executable_from_graph'] === false
    && $rows['new-vendor']['write_auto_enabled'] === false,
    'Arbitrary namespaced write-like ability is only a passive candidate' );
graph_ok( $rows['core-provider']['registered_adapter_read_abilities'] === array( 'core-provider/read-status' ),
    'Already reviewed read ability is recognized from runtime registry' );
$not_loaded = $coverage; $not_loaded['plugins'][1]['adapter_runtime_available'] = false;
$missing_runtime = $c::build_capability_graph( $not_loaded, $plugins, $abilities );
$unavailable = array();
foreach ( $missing_runtime['items'] as $row ) $unavailable[ $row['slug'] ] = $row;
graph_ok( $unavailable['core-provider']['registered_adapter_read_abilities'] === array(),
    'Declared but runtime-unavailable adapter is never mislabeled as operational' );
graph_ok( $rows['new-vendor']['dependency_gap'] === true
    && count( $rows['new-vendor']['declared_dependencies'] ) === 2,
    'Dependency inventory dynamically classifies active and missing plugin slugs' );
$reversed = $c::build_capability_graph( array( 'truncated'=>false,
    'plugins' => array_reverse( $coverage['plugins'] ) ), $plugins, array_reverse( $abilities ) );
graph_ok( $graph['graph_sha256'] === $reversed['graph_sha256'],
    'Graph fingerprint independent of plugin/ability order' );
$changed = $coverage; $changed['plugins'][0]['version'] = '4.5.10';
graph_ok( $graph['graph_sha256'] !== $c::build_capability_graph( $changed, $plugins, $abilities )['graph_sha256'],
    'Actual version drift changes evidence fingerprint' );
$partial = $c::build_capability_graph( $coverage, $plugins, $abilities, false );
graph_ok( $partial['complete'] === false && $partial['state'] === 'DISCOVERY_PARTIAL',
    'Unavailable ability registry cannot certify a complete graph' );
// Special WordPress plugin sources are complete inventory components, not
// ordinary plugin packages and must not acquire lifecycle/write operations.
$extras = $c::build_capability_graph(
    $coverage, $plugins, $abilities, true,
    array( 'site-mu.php' => array( 'Version'=>'1.0.0', 'RequiresPlugins'=>'new-vendor' ) ),
    array( 'object-cache.php' => array( 'Version'=>'2.0.0' ) )
);
graph_ok( $extras['installed_plugin_count'] === 5
    && $extras['must_use_and_dropin_count'] === 2
    && $extras['complete'] === true,
    'MU and drop-in sources included without hardcoded vendor identities' );
$kinds = array();
foreach ( $extras['items'] as $row ) $kinds[ $row['slug'] ] = $row['package_kind'];
graph_ok( $kinds['site-mu'] === 'must_use' && $kinds['object-cache'] === 'dropin',
    'Special plugin kinds are distinct from standard packages' );
graph_ok( $extras['automatic_write_registration'] === false
    && $extras['unknown_plugin_write_allowed'] === false,
    'Special plugin discovery never registers mutations' );
$long = array_fill( 0, 2001, 'new-vendor/read-status' );
graph_ok( $c::build_capability_graph( $coverage, $plugins, $long )['complete'] === false,
    'Oversized ability registry scan must report partial' );
graph_ok( $graph['unknown_plugin_write_allowed'] === false
    && $graph['automatic_write_registration'] === false, 'No authority from discovery' );
echo "DYNAMIC_ADAPTER_CAPABILITY_GRAPH: PASS (read-only, deterministic)\n";
