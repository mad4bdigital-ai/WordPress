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
graph_ok( $graph['functional_dimension_counts']['inventory_observed'] === 3
    && $graph['functional_dimension_counts']['active_reviewed_adapter'] === 1
    && $graph['functional_dimension_counts']['read_contract_registered'] === 1
    && $graph['functional_dimension_counts']['write_behavior_certified'] === 0,
    'Dimension matrix distinguishes live registered reads from write authorization' );
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
graph_ok( $extras['functional_dimension_counts']['inventory_only'] === 2,
    'Must-use and drop-in coverage remains inventory-only' );
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
// Validate each dimension against exact registered and certified evidence.
$capable = $coverage;
$capable['plugins'][1]['coverage_state'] = 'supported_reversible';
$capable['plugins'][1]['adapter_write_abilities'] = array( 'core-provider/update-item' );
$capable['plugins'][1]['adapter_reversible_contracts'] = array(
    'core-provider/update-item' => 'mad4b.rollback.provider-item.v1'
);
$capable['plugins'][1]['provider_certification_ok'] = true;
$capable_abilities = array_merge( $abilities, array( 'core-provider/update-item' ) );
$g = $c::build_capability_graph( $capable, $plugins, $capable_abilities );
$read = $c::plan_from_graph( $g, array( 'plugin_file'=>'core-provider/main.php',
    'dimension'=>'read', 'ability'=>'core-provider/read-status', 'expected_graph_sha256'=>$g['graph_sha256'] ) );
graph_ok( $read['state'] === 'READ_CONTRACT_AVAILABLE_AUTHORIZATION_REQUIRED'
    && $read['dispatcher'] === 'mad4b/read-execute'
    && $read['execute_ability_from_graph'] === false, 'Read contract routes through audited dispatcher only' );
$write = $c::plan_from_graph( $g, array( 'plugin_file'=>'core-provider/main.php',
    'dimension'=>'write', 'ability'=>'core-provider/update-item' ) );
graph_ok( $write['state'] === 'GOVERNED_WRITE_PATH_DEFINED_AUTHORIZATION_REQUIRED'
    && $write['dispatcher'] === 'mad4b/write-execute'
    && $write['mutation_performed'] === false
    && in_array('exact_owner_approval', $write['required_gates'], true),
    'Write dimension never grants or executes and still requires separate one-time owner approval' );
$undo = $c::plan_from_graph( $g, array( 'plugin_file'=>'core-provider/main.php',
    'dimension'=>'rollback', 'ability'=>'core-provider/update-item' ) );
graph_ok( $undo['state'] === 'RECORDED_REVERSIBLE_OPERATION_REQUIRED'
    && $undo['dispatcher'] === 'mad4b/mutation-undo'
    && $undo['rollback_contract'] === 'mad4b.rollback.provider-item.v1',
    'Rollback requires recorded journal and reviewed reversible contract' );
$unknown = $c::plan_from_graph( $g, array( 'plugin_file'=>'new-vendor/main.php',
    'dimension'=>'write', 'ability'=>'new-vendor/delete-all' ) );
graph_ok( $unknown['state'] === 'DEPENDENCY_NOT_READY'
    && $unknown['dispatcher'] === '', 'Missing plugin dependency blocks an unknown provider before adapter admission' );
$dependency_ready_plugins = $plugins;
$dependency_ready_plugins['new-vendor/main.php']['RequiresPlugins'] = 'core-provider';
$dependency_ready_graph = $c::build_capability_graph( $capable, $dependency_ready_plugins, $capable_abilities );
$unreviewed = $c::plan_from_graph( $dependency_ready_graph, array(
    'plugin_file'=>'new-vendor/main.php', 'dimension'=>'write', 'ability'=>'new-vendor/delete-all'
) );
graph_ok( $unreviewed['state'] === 'REVIEWED_ADAPTER_UNAVAILABLE'
    && $unreviewed['dispatcher'] === ''
    && $unreviewed['mutation_performed'] === false,
    'Discovered unknown provider without dependency gaps still cannot execute an unreviewed write' );
$uncertified = $capable;
$uncertified['plugins'][1]['provider_certification_ok'] = false;
$uncertified_graph = $c::build_capability_graph($uncertified,$plugins,$capable_abilities);
$denied = $c::plan_from_graph($uncertified_graph,array('plugin_file'=>'core-provider/main.php',
    'dimension'=>'write', 'ability'=>'core-provider/update-item'));
graph_ok($denied['state'] === 'WRITE_BEHAVIOR_NOT_CERTIFIED',
    'Provider versions are not automatically certified from discovery' );
$parallel = $capable;
$parallel['plugins'][1]['side_channel_blocker'] = 'unreviewed_parallel_mcp';
$parallel_graph = $c::build_capability_graph($parallel, $plugins, $capable_abilities);
$parallel_read = $c::plan_from_graph($parallel_graph, array('plugin_file'=>'core-provider/main.php',
    'dimension'=>'read', 'ability'=>'core-provider/read-status'));
graph_ok($parallel_read['state'] === 'PARALLEL_TRANSPORT_UNREVIEWED',
    'Unreviewed parallel MCP transport also blocks functional read routing');
$excluded = $capable;
$excluded['plugins'][1]['coverage_state'] = 'excluded_high_risk';
$excluded_graph = $c::build_capability_graph($excluded, $plugins, $capable_abilities);
$excluded_write = $c::plan_from_graph($excluded_graph, array('plugin_file'=>'core-provider/main.php',
    'dimension'=>'write', 'ability'=>'core-provider/update-item'));
graph_ok($excluded_write['state'] === 'PROVIDER_EXCLUDED_BY_RISK',
    'Explicit policy exclusion cannot be bypassed by a registered ability');
$stale = $c::plan_from_graph( $g, array('plugin_file'=>'core-provider/main.php',
    'dimension'=>'write', 'ability'=>'core-provider/update-item',
    'expected_graph_sha256'=>str_repeat('0',64)));
graph_ok($stale['state'] === 'DISCOVERY_SNAPSHOT_CHANGED',
    'Graph snapshot drift is rejected before route selection' );
$partial_plan = $c::plan_from_graph( $partial, array('plugin_file'=>'core-provider/main.php',
    'dimension'=>'read', 'ability'=>'core-provider/read-status'));
graph_ok($partial_plan['state'] === 'DISCOVERY_PARTIAL',
    'Partial inventory cannot yield operational route' );
$special_read = $c::plan_from_graph( $extras, array('plugin_file'=>'site-mu.php',
    'dimension'=>'read', 'ability'=>'site-mu/status'));
graph_ok($special_read['state'] === 'INVENTORY_ONLY_PACKAGE',
    'Must-use and drop-in plugins cannot inherit regular adapter authority' );
$inventory = $c::plan_from_graph( $extras, array('plugin_file'=>'object-cache.php',
    'dimension'=>'inventory'));
graph_ok($inventory['state'] === 'INVENTORY_OBSERVED'
    && $inventory['non_authorizing'] === true,
    'Special plugins remain observable without side effects' );
echo "DYNAMIC_ADAPTER_CAPABILITY_GRAPH: PASS (read-only, deterministic)\n";
