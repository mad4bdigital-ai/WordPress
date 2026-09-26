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
$evidence = static function () use ( $guard, $bridge ) {
	return ' guard=' . wp_json_encode( $guard ) . ' bridge=' . wp_json_encode( array(
		'adapter_runtime_from_official_plugin' => isset( $bridge['adapter_runtime_from_official_plugin'] ) ? $bridge['adapter_runtime_from_official_plugin'] : null,
		'adapter_runtime_source' => isset( $bridge['adapter_runtime_source'] ) ? $bridge['adapter_runtime_source'] : null,
		'adapter_runtime_version' => isset( $bridge['adapter_runtime_version'] ) ? $bridge['adapter_runtime_version'] : null,
		'mcp_adapter_init_count' => isset( $bridge['mcp_adapter_init_count'] ) ? $bridge['mcp_adapter_init_count'] : null,
	) );
};

if ( empty( $guard['eligible'] ) ) $fail( 'guard must be eligible on exact Staging origin' . $evidence() );
if ( empty( $guard['official_loads_before_hostinger'] ) ) $fail( 'active_plugins must already be canonical in this live-shape regression' . $evidence() );
if ( empty( $guard['collision_risk_detected'] ) ) $fail( 'runtime provenance collision must be detected even with canonical active_plugins order' . $evidence() );
if ( empty( $guard['runtime_provenance_mismatch'] ) ) $fail( 'runtime provenance mismatch must be explicit' . $evidence() );
if ( empty( $guard['runtime_from_hostinger_bundle'] ) ) $fail( 'reviewed Hostinger runtime owner must be identified' . $evidence() );
if ( empty( $guard['repair_applied'] ) ) $fail( 'MU bootstrap repair must be applied' . $evidence() );
if ( ! empty( $guard['load_order_repair_applied'] ) ) $fail( 'canonical active_plugins order must not be rewritten' . $evidence() );
if ( empty( $guard['mu_bootstrap_present'] ) || empty( $guard['mu_bootstrap_integrity'] ) ) $fail( 'managed MU bootstrap must be installed with matching integrity' . $evidence() );
if ( ! empty( $guard['mu_bootstrap_executed'] ) ) $fail( 'newly installed MU bootstrap cannot execute retroactively in the current request' . $evidence() );
if ( empty( $guard['next_request_required'] ) ) $fail( 'current PHP request cannot replace an already-declared class' . $evidence() );
if ( 'mu_bootstrap_installed_for_next_request' !== ( isset( $guard['state'] ) ? $guard['state'] : '' ) ) $fail( 'unexpected guard state' . $evidence() );

if ( ! empty( $bridge['adapter_runtime_from_official_plugin'] ) ) $fail( 'current request should still expose the preloaded legacy runtime' . $evidence() );
if ( '0.1.0' !== ( isset( $bridge['adapter_runtime_version'] ) ? (string) $bridge['adapter_runtime_version'] : '' ) ) $fail( 'expected simulated Hostinger runtime version 0.1.0' . $evidence() );
if ( false === strpos( (string) $bridge['adapter_runtime_source'], 'hostinger-ai-assistant/' ) ) $fail( 'expected bounded Hostinger runtime source' . $evidence() );

$active = get_option( 'active_plugins', array() );
$official = array_search( 'mcp-adapter/mcp-adapter.php', $active, true );
$hostinger = false;
foreach ( is_array( $active ) ? $active : array() as $index => $plugin ) {
	if ( 0 === strpos( (string) $plugin, 'hostinger-ai-assistant/' ) ) { $hostinger = $index; break; }
}
if ( false === $official || false === $hostinger || $official >= $hostinger ) $fail( 'canonical active-plugin order unexpectedly changed' . $evidence() );

$mu = trailingslashit( WPMU_PLUGIN_DIR ) . '000-mad4b-mcp-adapter-bootstrap.php';
if ( ! is_file( $mu ) ) $fail( 'managed MU bootstrap file was not installed' . $evidence() );

echo 'mad4b.site-control-plane.mcp-runtime-conflict-first-request.v2: PASS' . PHP_EOL;
