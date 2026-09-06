<?php
/**
 * Capture the committed audit head before two independent WP-CLI writers race.
 * Disposable CI runtime only.
 */
if ( ! defined( 'ABSPATH' ) ) {
	throw new RuntimeException( 'WordPress is not loaded.' );
}

$check = static function ( $condition, $message ) {
	if ( ! $condition ) throw new RuntimeException( $message );
};

$status = MAD4B_SCP_Audit::storage_status();
$check( ! empty( $status['ready'] ), 'Append-only audit storage is not ready: ' . wp_json_encode( $status ) );
$check( ! empty( $status['transactional'] ), 'Audit tables are not transactional.' );
$check( ! empty( $status['legacy_chain_valid'] ), 'Legacy audit anchor is invalid.' );
$check( MAD4B_SCP_Audit::verify_chain(), 'Audit chain is invalid before contention test.' );

update_option(
	'mad4b_ci_audit_concurrency',
	array(
		'start_sequence' => (int) $status['head_sequence'],
		'start_hash' => (string) $status['head_entry_hash'],
	),
	false
);

echo "mad4b.site-control-plane.runtime-audit-concurrency-setup.v1: PASS\n";
