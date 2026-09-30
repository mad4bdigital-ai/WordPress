<?php
/** Runtime proof that an independent MCP REST transport fails governed mutation closed. */
if ( ! defined( 'ABSPATH' ) ) throw new RuntimeException( 'WordPress is not loaded.' );
$check = static function ( $condition, $message ) { if ( ! $condition ) throw new RuntimeException( $message ); };

add_action( 'rest_api_init', static function () {
    register_rest_route( 'mosmcp/v1', '/mcp', array(
        'methods' => 'POST',
        'callback' => static function () { return rest_ensure_response( array( 'jsonrpc' => '2.0' ) ); },
        'permission_callback' => '__return_true',
    ) );
}, 1 );

$status = MAD4B_SCP_MCP_Peer_Governance::status();
$check( is_array( $status ) && ! empty( $status['inventory_ready'] ), 'Foreign MCP inventory itself was unavailable.' );
$check( ! empty( $status['foreign_mcp_detected'] ), 'Independent MCP route was not detected.' );
$check( empty( $status['write_side_channel_detected'] ), 'Unknown MCP route was incorrectly labeled as a proven write side channel.' );
$check( ! empty( $status['foreign_transport_unreviewed'] ), 'Independent MCP route did not become an unreviewed-transport blocker.' );
$check( in_array( 'mcp_foreign_transport_unreviewed', $status['blockers'], true ), 'Foreign MCP blocker code missing.' );
$check( ! in_array( 'mcp_write_side_channel_detected', $status['blockers'], true ), 'Unknown transport was overstated as a write side channel.' );
$foreign = isset( $status['foreign_transport_inventory'] ) ? $status['foreign_transport_inventory'] : array();
$check( in_array( '/mosmcp/v1/mcp', isset( $foreign['foreign_routes'] ) ? $foreign['foreign_routes'] : array(), true ), 'Exact foreign MCP route was not reported.' );

$guard = MAD4B_SCP_MCP_Peer_Governance::mutation_guard();
$check( is_wp_error( $guard ) && 'mcp_foreign_transport_unreviewed' === $guard->get_error_code(), 'Mutation guard did not fail closed with the exact unreviewed-transport reason.' );

$connection = MAD4B_SCP_Connection_Status::status();
$check( empty( $connection['local_transport_ready'] ), 'Connection readiness remained locally ready despite foreign MCP transport.' );
$check( in_array( 'mcp_foreign_transport_unreviewed', $connection['local_blockers'], true ), 'Connection readiness omitted foreign MCP blocker.' );
$check( ! in_array( 'mcp_write_side_channel_detected', $connection['local_blockers'], true ), 'Connection readiness overstated an unknown route as write-capable.' );
$check( ! empty( $connection['mcp_peer_governance']['foreign_transport']['detected'] ), 'Connection console summary omitted foreign MCP transport.' );

echo "mad4b.site-control-plane.runtime-foreign-mcp-transport-blocker.v1: PASS\n";
