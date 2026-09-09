<?php

if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }

$fail = static function ( $message ) {
	fwrite( STDERR, 'FAIL mcp-runtime-conflict-recovered: ' . $message . PHP_EOL );
	exit( 1 );
};

if ( ! class_exists( 'MAD4B_SCP_MCP_Runtime_Conflict_Guard' ) ) $fail( 'conflict guard unavailable' );
if ( ! class_exists( 'MAD4B_SCP_MCP_Registration_Bridge' ) ) $fail( 'registration bridge unavailable' );
if ( ! class_exists( 'MAD4B_SCP_Servers' ) ) $fail( 'server registry unavailable' );

$guard = MAD4B_SCP_MCP_Runtime_Conflict_Guard::status();
$bridge = MAD4B_SCP_MCP_Registration_Bridge::status();

if ( empty( $guard['eligible'] ) ) $fail( 'guard must remain eligible on exact Staging origin' );
if ( 'canonical_runtime' !== ( isset( $guard['state'] ) ? $guard['state'] : '' ) ) $fail( 'expected canonical runtime ownership after MU recovery' );
if ( empty( $guard['official_loads_before_hostinger'] ) ) $fail( 'active_plugins order must remain canonical' );
if ( ! empty( $guard['runtime_provenance_mismatch'] ) ) $fail( 'runtime provenance mismatch must be closed' );
if ( ! empty( $guard['repair_applied'] ) ) $fail( 'recovered request must converge without another repair' );
if ( ! empty( $guard['next_request_required'] ) ) $fail( 'recovered request must not require another restart' );
if ( empty( $guard['mu_bootstrap_present'] ) || empty( $guard['mu_bootstrap_integrity'] ) ) $fail( 'managed MU bootstrap must persist with valid integrity' );
if ( empty( $guard['mu_bootstrap_executed'] ) ) $fail( 'MU bootstrap must execute before normal plugins on recovered request' );
if ( 'official_adapter_loaded' !== ( isset( $guard['mu_bootstrap_runtime_state'] ) ? $guard['mu_bootstrap_runtime_state'] : '' ) ) $fail( 'MU bootstrap did not report canonical adapter load' );
if ( empty( $guard['mu_bootstrap_runtime_from_official_plugin'] ) ) $fail( 'MU bootstrap runtime owner must be canonical plugin' );
if ( 0 !== strpos( (string) $guard['mu_bootstrap_runtime_source'], 'mcp-adapter/' ) ) $fail( 'MU bootstrap runtime source must be canonical plugin-relative path' );

if ( empty( $bridge['adapter_runtime_from_official_plugin'] ) ) $fail( 'runtime class must come from canonical mcp-adapter plugin' );
if ( '0.6.1' !== ( isset( $bridge['adapter_runtime_version'] ) ? (string) $bridge['adapter_runtime_version'] : '' ) ) $fail( 'expected canonical runtime version 0.6.1' );
if ( 0 !== strpos( (string) $bridge['adapter_runtime_source'], 'mcp-adapter/' ) ) $fail( 'runtime source must be canonical plugin-relative path' );
if ( (int) ( isset( $bridge['mcp_adapter_init_count'] ) ? $bridge['mcp_adapter_init_count'] : 0 ) < 1 ) $fail( 'canonical adapter must fire mcp_adapter_init' );
if ( ! empty( $bridge['adapter_init_seen_before_bridge_boot'] ) ) $fail( 'bridge must bind before adapter init' );

$registrations = MAD4B_SCP_Servers::registration_status();
foreach ( array( 'mad4b-read', 'mad4b-chatgpt', 'mad4b-content', 'mad4b-write', 'mad4b-admin', 'mad4b-breakglass' ) as $server_id ) {
	if ( empty( $registrations[ $server_id ]['registered'] ) ) $fail( $server_id . ' not registered after MU bootstrap recovery' );
	if ( ! empty( $registrations[ $server_id ]['error'] ) ) $fail( $server_id . ' registration error: ' . $registrations[ $server_id ]['error'] );
}

echo 'mad4b.site-control-plane.mcp-runtime-conflict-recovered.v2: PASS' . PHP_EOL;
