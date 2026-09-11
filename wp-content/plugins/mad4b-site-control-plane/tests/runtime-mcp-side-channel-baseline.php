<?php
/**
 * Runtime proof that the exact clean MCP Adapter inventory is inspectable and
 * does not expose an ungoverned write path before a synthetic peer is added.
 */
if ( ! defined( 'ABSPATH' ) ) {
	throw new RuntimeException( 'WordPress is not loaded.' );
}
$check = static function ( $condition, $message ) {
	if ( ! $condition ) throw new RuntimeException( $message );
};

$status = MAD4B_SCP_MCP_Peer_Governance::status();
$check( is_array( $status ) && ! empty( $status['inventory_ready'] ), 'MCP peer inventory was not runtime-ready.' );
$check( isset( $status['server_count'] ) && (int) $status['server_count'] >= 5, 'Expected default MCP server plus four MAD4B servers were not visible.' );
$check( empty( $status['write_side_channel_detected'] ), 'Clean runtime unexpectedly exposes an ungoverned MCP write side-channel: ' . wp_json_encode( $status ) );

$authority = MAD4B_SCP_Authorization::authority_status();
$check( isset( $authority['mcp_peer_governance'] ) && ! empty( $authority['mcp_peer_governance']['inventory_ready'] ), 'Runtime authority status omitted MCP peer governance.' );
$check( ! in_array( 'mcp_write_side_channel_detected', isset( $authority['blockers'] ) ? $authority['blockers'] : array(), true ), 'Clean runtime authority is incorrectly blocked by MCP side-channel detection.' );

$self_test = MAD4B_SCP_Adapter_Registry::instance()->runtime_self_test();
$check( isset( $self_test['mcp_peer_governance'] ) && ! empty( $self_test['mcp_peer_governance']['inventory_ready'] ), 'Runtime self-test omitted MCP peer governance.' );
$check( ! empty( $self_test['mcp_peer_governance_ok'] ), 'Runtime self-test did not accept the clean MCP peer inventory.' );

echo "mad4b.site-control-plane.runtime-mcp-side-channel-baseline.v1: PASS\n";
