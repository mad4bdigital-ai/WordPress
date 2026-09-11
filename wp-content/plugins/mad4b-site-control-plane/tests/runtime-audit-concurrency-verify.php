<?php
/**
 * Verify no audit write was lost and the two-process chain is serialized.
 * Disposable CI runtime only.
 */
if ( ! defined( 'ABSPATH' ) ) {
	throw new RuntimeException( 'WordPress is not loaded.' );
}
$check = static function ( $condition, $message ) {
	if ( ! $condition ) throw new RuntimeException( $message );
};

$config = get_option( 'mad4b_ci_audit_concurrency', array() );
$check( is_array( $config ) && isset( $config['start_sequence'], $config['start_hash'] ), 'Audit contention setup state is unavailable.' );

$status = MAD4B_SCP_Audit::storage_status();
$start = (int) $config['start_sequence'];
$check( ! empty( $status['ready'] ), 'Audit storage degraded after contention: ' . wp_json_encode( $status ) );
$check( $start + 2 === (int) $status['head_sequence'], 'Concurrent audit writers lost, duplicated, or added an unexpected event.' );

global $wpdb;
$t = MAD4B_SCP_Schema::tables();
$rows = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT sequence,event_id,ability,summary_json,previous_hash,entry_hash FROM {$t['audit_events']} WHERE chain_name = %s AND sequence > %d ORDER BY sequence ASC",
		MAD4B_SCP_Audit::CHAIN,
		$start
	),
	ARRAY_A
); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
$check( is_array( $rows ) && 2 === count( $rows ), 'Expected exactly two concurrent audit rows.' );
$check( $start + 1 === (int) $rows[0]['sequence'] && $start + 2 === (int) $rows[1]['sequence'], 'Concurrent audit sequences are not contiguous.' );
$check( 'mad4b/ci-audit-concurrency' === $rows[0]['ability'] && 'mad4b/ci-audit-concurrency' === $rows[1]['ability'], 'Unexpected audit event appeared in contention window.' );
$check( hash_equals( (string) $config['start_hash'], (string) $rows[0]['previous_hash'] ), 'First concurrent writer did not link to captured chain head.' );
$check( hash_equals( (string) $rows[0]['entry_hash'], (string) $rows[1]['previous_hash'] ), 'Second concurrent writer did not serialize after the first.' );
$check( hash_equals( (string) $rows[1]['entry_hash'], (string) $status['head_entry_hash'] ), 'Audit head does not match the final concurrent event.' );
$check( MAD4B_SCP_Audit::verify_chain(), 'Audit hash chain failed after concurrent writes.' );

update_option(
	'mad4b_ci_audit_tamper_target',
	array(
		'event_id' => (string) $rows[0]['event_id'],
		'summary_json' => (string) $rows[0]['summary_json'],
		'head_hash' => (string) $status['head_entry_hash'],
	),
	false
);

echo "mad4b.site-control-plane.runtime-audit-concurrency.v1: PASS\n";
