<?php
/** Runtime regression for self-test transport isolation semantics. */
if ( ! defined( 'ABSPATH' ) ) throw new RuntimeException( 'WordPress is not loaded.' );
$check = static function ( $condition, $message ) { if ( ! $condition ) throw new RuntimeException( $message ); };

$check( current_user_can( 'manage_options' ), 'Provider-isolation smoke requires an administrator.' );
$check( wp_has_ability( 'mad4b/runtime-self-test' ), 'Runtime self-test ability is unavailable.' );
$check( wp_has_ability( 'etg-dfsb/evidence-provider' ), 'Native ETG evidence-provider is unavailable.' );
$check( wp_has_ability( 'etg-dfsb/evidence-query' ), 'Native ETG evidence-query is unavailable.' );

$result = wp_get_ability( 'mad4b/runtime-self-test' )->execute();
$check( ! is_wp_error( $result ), 'Runtime self-test execution failed.' );
$check( 'mad4b.runtime-self-test.v2' === (string) $result['contract'], 'Runtime self-test contract drifted.' );
$check( 'passed' === (string) $result['status'], 'Runtime self-test remained degraded under effective provider isolation: ' . wp_json_encode( $result ) );
$check( ! empty( $result['custom_server_isolation'] ), 'Custom-server isolation was not proven.' );
$check( ! empty( $result['default_server_suppressed'] ), 'Default MCP server suppression was not reflected in self-test.' );
$check( ! empty( $result['mcp_peer_governance_ok'] ), 'MCP peer governance is not healthy.' );

$isolation = isset( $result['provider_mcp_isolation'] ) && is_array( $result['provider_mcp_isolation'] ) ? $result['provider_mcp_isolation'] : array();
$check( ! empty( $isolation['effective'] ), 'Provider MCP isolation is not effective in the disposable runtime.' );
$check( ! empty( $isolation['default_server_suppressed'] ), 'Provider isolation did not suppress the default MCP server.' );
$check( ! empty( $isolation['unknown_routes_fail_closed'] ), 'Unknown provider routes are not fail-closed.' );

$candidates = isset( $result['default_server_public_candidates'] ) && is_array( $result['default_server_public_candidates'] ) ? $result['default_server_public_candidates'] : array();
$leaks = isset( $result['default_server_exposure_leaks'] ) && is_array( $result['default_server_exposure_leaks'] ) ? $result['default_server_exposure_leaks'] : array();
foreach ( array( 'etg-dfsb/evidence-provider', 'etg-dfsb/evidence-query' ) as $name ) {
	$check( in_array( $name, $candidates, true ), 'Native ETG public metadata candidate was not reported: ' . $name );
	$check( ! in_array( $name, $leaks, true ), 'Native ETG evidence ability was falsely reported as a default-server exposure leak: ' . $name );
}
$check( empty( $leaks ), 'Effective default-server suppression still reports exposure leaks: ' . wp_json_encode( $leaks ) );

echo "mad4b.site-control-plane.runtime-self-test-provider-isolation.v1: PASS\n";
