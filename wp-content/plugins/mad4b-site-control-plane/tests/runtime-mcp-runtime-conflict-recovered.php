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
if ( 'canonical_order' !== ( isset( $guard['state'] ) ? $guard['state'] : '' ) ) $fail( 'expected canonical active-plugin order' );
if ( empty( $guard['official_loads_before_hostinger'] ) ) $fail( 'official adapter must load before Hostinger' );
if ( ! empty( $guard['repair_applied'] ) ) $fail( 'second request must converge without another repair' );
if ( ! empty( $guard['next_request_required'] ) ) $fail( 'second request must not require another restart' );

if ( empty( $bridge['adapter_runtime_from_official_plugin'] ) ) $fail( 'runtime class must come from canonical mcp-adapter plugin' );
if ( '0.6.1' !== ( isset( $bridge['adapter_runtime_version'] ) ? (string) $bridge['adapter_runtime_version'] : '' ) ) $fail( 'expected canonical runtime version 0.6.1' );
if ( 0 !== strpos( (string) $bridge['adapter_runtime_source'], 'mcp-adapter/' ) ) $fail( 'runtime source must be canonical plugin-relative path' );
if ( (int) ( isset( $bridge['mcp_adapter_init_count'] ) ? $bridge['mcp_adapter_init_count'] : 0 ) < 1 ) $fail( 'canonical adapter must fire mcp_adapter_init' );
if ( ! empty( $bridge['adapter_init_seen_before_bridge_boot'] ) ) $fail( 'bridge must be bound before adapter init' );

$registrations = MAD4B_SCP_Servers::registration_status();
foreach ( array( 'mad4b-read', 'mad4b-chatgpt', 'mad4b-content', 'mad4b-write', 'mad4b-admin', 'mad4b-breakglass' ) as $server_id ) {
	if ( empty( $registrations[ $server_id ]['registered'] ) ) $fail( $server_id . ' not registered after canonical-order recovery' );
	if ( ! empty( $registrations[ $server_id ]['error'] ) ) $fail( $server_id . ' registration error: ' . $registrations[ $server_id ]['error'] );
}

echo 'mad4b.site-control-plane.mcp-runtime-conflict-recovered.v1: PASS' . PHP_EOL;
