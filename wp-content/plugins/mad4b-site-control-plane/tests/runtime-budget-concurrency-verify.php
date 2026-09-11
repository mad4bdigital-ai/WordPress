<?php
/**
 * Verify committed DB truth after two independent budget contenders finish.
 */

if ( ! defined( 'ABSPATH' ) ) {
	throw new RuntimeException( 'WordPress is not loaded.' );
}

$check = static function ( $condition, $message ) {
	if ( ! $condition ) throw new RuntimeException( $message );
};
$config = get_option( 'mad4b_ci_budget_concurrency', array() );
$check( is_array( $config ) && ! empty( $config['agent_id'] ) && ! empty( $config['tickets'] ), 'Concurrency setup state is unavailable for verification.' );

$statuses = array();
foreach ( array( 'one', 'two' ) as $worker ) {
	$ticket = MAD4B_SCP_Approval_Tickets::get( $config['tickets'][ $worker ] );
	$check( is_array( $ticket ), 'Concurrency approval ticket disappeared for worker ' . $worker . '.' );
	$statuses[] = (string) $ticket['status'];
}
sort( $statuses, SORT_STRING );
$check( array( 'approved', 'used' ) === $statuses, 'Concurrency outcome must consume exactly one approval and preserve the denied worker approval.' );

global $wpdb;
$tables = MAD4B_SCP_Schema::tables();
$used = (int) $wpdb->get_var(
	$wpdb->prepare(
		"SELECT COALESCE(SUM(used_count),0) FROM {$tables['budget_windows']} WHERE agent_id = %d AND budget_type = %s",
		(int) $config['agent_id'],
		'requests'
	)
); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
$check( 1 === $used, 'Concurrent contenders oversubscribed or lost the single budget unit.' );

echo "mad4b.site-control-plane.runtime-budget-concurrency.v1: PASS\n";
