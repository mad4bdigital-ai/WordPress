<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

$fail = static function ( $message, $context = null ) {
	fwrite( STDERR, 'FAIL runtime-mcp-protocol-profile: ' . $message . ( null === $context ? '' : ' ' . wp_json_encode( $context ) ) . PHP_EOL );
	exit( 1 );
};
$check = static function ( $condition, $message, $context = null ) use ( $fail ) {
	if ( ! $condition ) $fail( $message, $context );
};

$expected = array( '2026-07-28', '2025-11-25', '2025-06-18', '2024-11-05' );
$status = MAD4B_SCP_MCP_Protocol_Profile::status();
$check( ! empty( $status['ready'] ) && ! empty( $status['adapter_version_match'] ), 'Exact Adapter version is not protocol-certified.', $status );
$check( $expected === $status['certified_protocol_versions'], 'Certified protocol version order/content drifted.', $status );
$check( empty( $status['tools_list_changed_supported'] ) && 'deny' === $status['unknown_protocol_policy'], 'Protocol refresh/unknown policy drifted.', $status );
$check( 'not_certified' === $status['successor_certification_state'] && ! empty( $status['successor_dual_protocol_regression_required'] ), 'Successor Adapter guard is not fail-closed.', $status );

foreach ( $expected as $version ) {
	$negotiated = MAD4B_SCP_MCP_Protocol_Profile::negotiate( $version, array(
		'tools_list_refresh' => true,
		'reconnect' => true,
		'projection_revision_echo' => true,
		'client_caches_tool_definitions' => false,
	) );
	$check( ! is_wp_error( $negotiated ) && $version === $negotiated['protocol_version'], 'Certified protocol did not negotiate exactly.', $negotiated );
	$check( false === $negotiated['server_capabilities']['tools']['listChanged'] && empty( $negotiated['fallback_protocol_negotiation_used'] ), 'Protocol negotiation invented listChanged/fallback semantics.', $negotiated );
	$check( 'none' === $negotiated['authority_effect'] && empty( $negotiated['authorizing'] ), 'Protocol compatibility created authority.', $negotiated );
}

$modern = MAD4B_SCP_MCP_Protocol_Profile::negotiate( '2026-07-28' );
$check( ! is_wp_error( $modern ) && 'per_request_revision' === $modern['lifecycle'], 'Modern 2026 per-request revision did not negotiate exactly.', $modern );
$future = MAD4B_SCP_MCP_Protocol_Profile::negotiate( '2027-01-01' );
$check( is_wp_error( $future ) && 'mad4b_mcp_protocol_version_uncertified' === $future->get_error_code(), 'Unknown/newer protocol did not fail closed.', $future );
$unknown_feature = MAD4B_SCP_MCP_Protocol_Profile::negotiate( '2025-11-25', array( 'future_magic' => true ) );
$check( is_wp_error( $unknown_feature ) && 'mad4b_mcp_protocol_feature_uncertified' === $unknown_feature->get_error_code(), 'Unknown protocol feature did not fail closed.', $unknown_feature );

$current = MAD4B_SCP_ChatGPT_Tool_Projection::projection_revision();
$fresh = MAD4B_SCP_MCP_Protocol_Profile::projection_refresh( '2025-11-25', $current, false );
$check( ! is_wp_error( $fresh ) && empty( $fresh['stale_projection'] ) && empty( $fresh['refresh_required'] ) && 'none' === $fresh['refresh_action'], 'Fresh projection revision was reported stale.', $fresh );
$stale = MAD4B_SCP_MCP_Protocol_Profile::projection_refresh( '2025-11-25', $current + 1, false );
$check( ! is_wp_error( $stale ) && ! empty( $stale['stale_projection'] ) && 'tools_list' === $stale['refresh_action'], 'Stale projection did not require tools/list refresh.', $stale );
$cached = MAD4B_SCP_MCP_Protocol_Profile::projection_refresh( '2025-11-25', $current + 1, true );
$check( ! is_wp_error( $cached ) && 'reconnect_then_tools_list' === $cached['refresh_action'] && empty( $cached['push_notification_expected'] ), 'Cached stale client refresh semantics are incorrect.', $cached );

$isolation = MAD4B_SCP_ChatGPT_Tool_Projection::isolation_contract();
$check( 'site_global' === $isolation['isolation_scope'] && empty( $isolation['session_scoped_hot_set'] ), 'Hot-set isolation scope is not explicit site-global.', $isolation );
$check( 'optimistic_cas_single_winner' === $isolation['contention_policy'] && ! empty( $isolation['fixed_dispatch_correctness_independent'] ), 'Projection contention/fixed-dispatch contract is incomplete.', $isolation );

echo "mad4b.mcp-protocol-profile.runtime.v1: PASS\n";
