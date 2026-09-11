<?php
/**
 * One independent WP-CLI process participating in append-only audit contention.
 * Disposable CI runtime only.
 */
if ( ! defined( 'ABSPATH' ) ) {
	throw new RuntimeException( 'WordPress is not loaded.' );
}

$worker = (string) getenv( 'MAD4B_CI_AUDIT_WORKER' );
$barrier = (float) getenv( 'MAD4B_CI_AUDIT_BARRIER' );
if ( ! in_array( $worker, array( 'one', 'two' ), true ) || $barrier <= microtime( true ) ) {
	throw new RuntimeException( 'Audit worker requires a valid worker id and a future barrier.' );
}

while ( microtime( true ) < $barrier ) usleep( 10000 );

$entry = MAD4B_SCP_Audit::record(
	'mad4b/ci-audit-concurrency',
	array(
		'worker' => $worker,
		'proof' => 'independent-wp-cli-process',
	),
	'ok'
);
if ( is_wp_error( $entry ) ) {
	throw new RuntimeException( $entry->get_error_code() . ': ' . $entry->get_error_message() );
}

echo 'MAD4B_AUDIT_WORKER_RESULT=' . wp_json_encode(
	array(
		'worker' => $worker,
		'sequence' => (int) $entry['sequence'],
		'event_id' => (string) $entry['event_id'],
		'entry_hash' => (string) $entry['entry_hash'],
	),
	JSON_UNESCAPED_SLASHES
) . "\n";
