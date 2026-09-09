<?php

if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }

$fail = static function ( $message ) {
	fwrite( STDERR, 'FAIL mcp-runtime-conflict-first-request: ' . $message . PHP_EOL );
	exit( 1 );
};

if ( ! class_exists( 'MAD4B_SCP_MCP_Runtime_Conflict_Guard' ) ) $fail( 'conflict guard unavailable' );
if ( ! class_exists( 'MAD4B_SCP_MCP_Registration_Bridge' ) ) $fail( 'registration bridge unavailable' );

$guard = MAD4B_SCP_MCP_Runtime_Conflict_Guard::status();
$bridge = MAD4B_SCP_MCP_Registration_Bridge::status();

if ( empty( $guard['eligible'] ) ) $fail( 'guard must be eligible on exact Staging origin' );
if ( empty( $guard['collision_risk_detected'] ) ) $fail( 'reviewed Hostinger collision must be detected' );
if ( empty( $guard['repair_applied'] ) ) $fail( 'load-order repair must be applied' );
if ( empty( $guard['next_request_required'] ) ) $fail( 'current PHP request cannot replace an already-declared class' );
if ( 'repaired_for_next_request' !== ( isset( $guard['state'] ) ? $guard['state'] : '' ) ) $fail( 'unexpected guard state' );
if ( empty( $guard['official_loads_before_hostinger'] ) ) $fail( 'stored order must place canonical adapter before Hostinger' );

if ( ! empty( $bridge['adapter_runtime_from_official_plugin'] ) ) $fail( 'current request should still expose the preloaded legacy runtime' );
if ( '0.1.0' !== ( isset( $bridge['adapter_runtime_version'] ) ? (string) $bridge['adapter_runtime_version'] : '' ) ) $fail( 'expected simulated Hostinger runtime version 0.1.0' );
if ( false === strpos( (string) $bridge['adapter_runtime_source'], 'hostinger-ai-assistant/' ) ) $fail( 'expected bounded Hostinger runtime source' );

$active = get_option( 'active_plugins', array() );
$official = array_search( 'mcp-adapter/mcp-adapter.php', $active, true );
$hostinger = false;
foreach ( is_array( $active ) ? $active : array() as $index => $plugin ) {
	if ( 0 === strpos( (string) $plugin, 'hostinger-ai-assistant/' ) ) { $hostinger = $index; break; }
}
if ( false === $official || false === $hostinger || $official >= $hostinger ) $fail( 'persisted active-plugin order was not repaired' );

echo 'mad4b.site-control-plane.mcp-runtime-conflict-first-request.v1: PASS' . PHP_EOL;
