<?php
/**
 * Prove a direct out-of-band change to an immutable audit event is detected.
 * The disposable CI row is restored afterwards so later checks see a valid chain.
 */
if ( ! defined( 'ABSPATH' ) ) {
	throw new RuntimeException( 'WordPress is not loaded.' );
}
$check = static function ( $condition, $message ) {
	if ( ! $condition ) throw new RuntimeException( $message );
};

$config = get_option( 'mad4b_ci_audit_tamper_target', array() );
$check( is_array( $config ) && ! empty( $config['event_id'] ) && isset( $config['summary_json'] ), 'Audit tamper target is unavailable.' );

global $wpdb;
$t = MAD4B_SCP_Schema::tables();
$changed = $wpdb->update(
	$t['audit_events'],
	array( 'summary_json' => '{"tampered":true}' ),
	array( 'event_id' => (string) $config['event_id'] ),
	array( '%s' ),
	array( '%s' )
); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
$check( 1 === (int) $changed, 'Unable to apply disposable out-of-band audit tamper.' );
$check( false === MAD4B_SCP_Audit::verify_chain(), 'Audit verifier failed to detect event tampering.' );

$restored = $wpdb->update(
	$t['audit_events'],
	array( 'summary_json' => (string) $config['summary_json'] ),
	array( 'event_id' => (string) $config['event_id'] ),
	array( '%s' ),
	array( '%s' )
); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
$check( 1 === (int) $restored, 'Unable to restore disposable audit row after tamper proof.' );
$check( MAD4B_SCP_Audit::verify_chain(), 'Audit chain did not recover after exact test-only restoration.' );
$status = MAD4B_SCP_Audit::storage_status();
$check( hash_equals( (string) $config['head_hash'], (string) $status['head_entry_hash'] ), 'Tamper proof changed the committed audit head.' );

delete_option( 'mad4b_ci_audit_concurrency' );
delete_option( 'mad4b_ci_audit_tamper_target' );

echo "mad4b.site-control-plane.runtime-audit-tamper.v1: PASS\n";
