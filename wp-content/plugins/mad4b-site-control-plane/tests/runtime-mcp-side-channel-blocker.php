<?php
/**
 * Runtime proof that a public mutation reachable through the Adapter default
 * execute-ability path blocks MAD4B central mutation authorization before
 * budget/approval consumption.
 */
if ( ! defined( 'ABSPATH' ) ) {
	throw new RuntimeException( 'WordPress is not loaded.' );
}
$check = static function ( $condition, $message ) {
	if ( ! $condition ) throw new RuntimeException( $message );
};
$check( wp_has_ability( 'mad4b-ci/rogue-public-write' ), 'Synthetic public write ability was not registered before MCP initialization.' );

$status = MAD4B_SCP_MCP_Peer_Governance::status();
$check( ! empty( $status['inventory_ready'] ), 'MCP peer inventory is unavailable in rogue fixture runtime.' );
$check( ! empty( $status['write_side_channel_detected'] ), 'Public write reachable through default MCP execute-ability was not detected.' );
$check( in_array( 'mcp_write_side_channel_detected', $status['blockers'], true ), 'Exact side-channel blocker code is missing.' );

$found = false;
foreach ( isset( $status['peers'] ) ? $status['peers'] : array() as $peer ) {
	foreach ( isset( $peer['risks'] ) ? $peer['risks'] : array() as $risk ) {
		if ( 'generic_execute_reaches_public_write' !== ( isset( $risk['reason'] ) ? $risk['reason'] : '' ) ) continue;
		if ( in_array( 'mad4b-ci/rogue-public-write', isset( $risk['reachable_public_writes'] ) ? $risk['reachable_public_writes'] : array(), true ) ) $found = true;
	}
}
$check( $found, 'Detector did not bind the default generic execute path to the exact rogue public write ability.' );

$tables = MAD4B_SCP_Schema::tables();
global $wpdb;
$budget_before = (int) $wpdb->get_var( "SELECT COALESCE(SUM(used_count),0) FROM {$tables['budget_windows']}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery
$used_tickets_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tables['approval_tickets']} WHERE status = 'used'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery

$denied = MAD4B_SCP_Authorization::authorize_mutation(
	'mad4b/content-update-post',
	'mad4b-content',
	'core',
	array( 'post_id' => 1, 'expected_modified_gmt' => 'never-reached' )
);
$check( is_wp_error( $denied ) && 'mcp_write_side_channel_detected' === $denied->get_error_code(), 'Central authorization did not fail closed on the MCP write side-channel.' );

$budget_after = (int) $wpdb->get_var( "SELECT COALESCE(SUM(used_count),0) FROM {$tables['budget_windows']}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery
$used_tickets_after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tables['approval_tickets']} WHERE status = 'used'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery
$check( $budget_before === $budget_after, 'Side-channel blocker was evaluated after budget consumption.' );
$check( $used_tickets_before === $used_tickets_after, 'Side-channel blocker was evaluated after approval consumption.' );

$authority = MAD4B_SCP_Authorization::authority_status();
$check( in_array( 'mcp_write_side_channel_detected', isset( $authority['blockers'] ) ? $authority['blockers'] : array(), true ), 'Runtime authority status did not surface the side-channel blocker.' );

$self_test = MAD4B_SCP_Adapter_Registry::instance()->runtime_self_test();
$check( empty( $self_test['mcp_peer_governance_ok'] ), 'Runtime self-test did not degrade on the detected side-channel.' );

echo "mad4b.site-control-plane.runtime-mcp-side-channel-blocker.v1: PASS\n";
