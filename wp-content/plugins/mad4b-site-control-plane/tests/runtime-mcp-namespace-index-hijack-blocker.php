<?php
/**
 * Runtime proof that the WordPress-generated /mcp namespace index is ignored
 * only while it remains the exact core namespace-index callback. A second
 * executable callback merged onto the same route must make it foreign again.
 */
if ( ! defined( 'ABSPATH' ) ) throw new RuntimeException( 'WordPress is not loaded.' );
$check = static function ( $condition, $message ) { if ( ! $condition ) throw new RuntimeException( $message ); };

$rest = rest_get_server();
$check( is_object( $rest ) && method_exists( $rest, 'register_route' ), 'REST server is unavailable.' );

$clean = MAD4B_SCP_MCP_Peer_Governance::status();
$check( ! empty( $clean['inventory_ready'] ), 'Clean peer inventory is unavailable.' );
$check( empty( $clean['write_side_channel_detected'] ), 'Clean /mcp namespace index was incorrectly treated as a side channel: ' . wp_json_encode( $clean['foreign_transport_inventory'] ) );

$rest->register_route(
    'mcp',
    '/mcp',
    array(
        array(
            'methods' => 'POST',
            'callback' => static function () { return rest_ensure_response( array( 'jsonrpc' => '2.0', 'foreign' => true ) ); },
            'permission_callback' => '__return_true',
        ),
    ),
    false
);

$status = MAD4B_SCP_MCP_Peer_Governance::status();
$check( ! empty( $status['inventory_ready'] ), 'Peer inventory failed after namespace-index hijack simulation.' );
$check( ! empty( $status['write_side_channel_detected'] ), 'Foreign callback hidden behind /mcp namespace index was not detected.' );
$check( in_array( 'mcp_foreign_transport_unreviewed', $status['blockers'], true ), 'Foreign transport blocker missing after namespace-index hijack.' );
$foreign = isset( $status['foreign_transport_inventory'] ) ? $status['foreign_transport_inventory'] : array();
$check( in_array( '/mcp', isset( $foreign['foreign_routes'] ) ? $foreign['foreign_routes'] : array(), true ), 'Hijacked /mcp route was not reported as foreign.' );

$guard = MAD4B_SCP_MCP_Peer_Governance::mutation_guard();
$check( is_wp_error( $guard ) && 'mcp_write_side_channel_detected' === $guard->get_error_code(), 'Mutation guard did not fail closed on /mcp namespace-index hijack.' );

echo "mad4b.site-control-plane.runtime-mcp-namespace-index-hijack-blocker.v1: PASS\n";
