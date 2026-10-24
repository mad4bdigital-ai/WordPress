<?php
/** Deterministic no-WordPress-DB acceptance of adaptive MCP catalog limits. */
define( 'ABSPATH', dirname( __DIR__, 5 ) . '/' );
$scenario = getenv( 'MAD4B_CATALOG_TEST_SCENARIO' ) ?: 'default';
if ( in_array( $scenario, array( 'healthy', 'slow', 'unmeasured', 'site_drift' ), true ) ) {
    define( 'MAD4B_MCP_CATALOG_MAX_TOOLS', 48 );
    define( 'MAD4B_MCP_CATALOG_MAX_BYTES', 131072 );
} elseif ( 'invalid' === $scenario ) {
    define( 'MAD4B_MCP_CATALOG_MAX_TOOLS', 9999 );
    define( 'MAD4B_MCP_CATALOG_MAX_BYTES', 9999999 );
}
$GLOBALS['test_reads'] = 0;
function get_current_blog_id() { return 1; }
function home_url( $path = '' ) { return 'https://fixture-a.test' . $path; }
function wp_get_environment_type() { return 'staging'; }
function get_transient( $key ) {
    $GLOBALS['test_reads']++;
    return $GLOBALS['test_telemetry'] ?? false;
}
function set_transient( $key, $value, $ttl ) {
    $GLOBALS['test_telemetry'] = $value;
    return true;
}
function add_filter( ...$args ) {}
$site_binding = hash( 'sha256', '1|https://fixture-a.test/|staging' );
if ( in_array( $scenario, array( 'healthy', 'slow', 'site_drift' ), true ) ) {
    $GLOBALS['test_telemetry'] = array(
        'contract' => 'mad4b.mcp-catalog-budget.v1',
        'site_binding' => 'site_drift' === $scenario ? str_repeat( '0', 64 ) : $site_binding,
        'updated_at' => time(), 'samples' => 3,
        'ewma_ms' => 'slow' === $scenario ? 1900 : 450,
    );
}
require_once __DIR__ . '/../includes/class-mad4b-scp-mcp-catalog-diagnostics.php';
function check( $condition, $message ) {
    if ( ! $condition ) { fwrite( STDERR, 'FAIL ' . $message . PHP_EOL ); exit( 2 ); }
}
$budget = MAD4B_SCP_MCP_Catalog_Budget::limits();
$tools = $budget['max_tools'];
$bytes = $budget['max_bytes'];
$expected_tools = in_array( $scenario, array( 'healthy' ), true ) ? 48
    : ( 'slow' === $scenario ? 27 : 36 );
$expected_bytes = 'healthy' === $scenario ? 131072
    : ( 'slow' === $scenario ? 73728 : 98304 );
check( $tools === $expected_tools, $scenario . ': effective tools=' . $tools );
check( $bytes === $expected_bytes, $scenario . ': bytes=' . $bytes );
$required = array( 'mad4b/read-discover', 'mad4b/read-execute',
    'mad4b/write-discover', 'mad4b/write-execute' );
$optional = array();
for ( $i = 0; $i < 110; ++$i ) $optional[] = 'demo/direct-' . $i;
$result = MAD4B_SCP_MCP_Catalog_Diagnostics::budget_projection( array_merge( $required, $optional ), $optional );
check( ! empty( $result['ready'] ) && $result['selected'] === array_merge( $required,
    array_slice( $optional, 0, $expected_tools - 4 ) ), 'required priority, optional bounds and deterministic selection' );
check( count( $result['excluded_optional'] ) === count( $optional ) - ( $expected_tools - 4 ), 'remaining abilities discoverable through dispatcher' );
check( $budget['authorizing'] === false, 'budget cannot grant authority' );
$repeat = MAD4B_SCP_MCP_Catalog_Budget::limits();
check( $repeat['max_tools'] === $tools && $GLOBALS['test_reads'] === 1, 'request-local transient cache must avoid extra DB reads' );
if ( 'slow' === $scenario ) {
    $thirty = array_slice( $optional, 0, 30 );
    $result = MAD4B_SCP_MCP_Catalog_Diagnostics::budget_projection( array_merge( $thirty, array_slice( $optional, 30, 5 ) ),
        array_slice( $optional, 30, 5 ) );
    check( ! empty( $result['ready'] ) && count( $result['selected'] ) === 30, 'latency cannot remove 30 required transport tools' );
}
if ( 'healthy' === $scenario ) {
    $many = array_slice( $optional, 0, 49 );
    $result = MAD4B_SCP_MCP_Catalog_Diagnostics::budget_projection( $many, array() );
    check( empty( $result['ready'] ) && $result['blocker'] === 'mcp_required_catalog_budget_exceeded', 'configured hard cap must reject 49 required tools' );
}
if ( 'default' === $scenario ) {
    check( MAD4B_SCP_MCP_Catalog_Budget::observe( 420, 8 ) === true, 'successful observation persisted' );
    check( MAD4B_SCP_MCP_Catalog_Budget::observe( 420, 8 ) === false, 'sampling throttle' );
    check( (int) MAD4B_SCP_MCP_Catalog_Budget::telemetry()['samples'] === 1, 'telemetry updates cache' );
}
echo 'PASS adaptive_mcp_catalog scenario=', $scenario,
    ' selected=', $tools, ' bytes=', $bytes, ' no_authority_change=1', PHP_EOL;
