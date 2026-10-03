<?php
/**
 * One independent WP-CLI process participating in append-only audit contention.
 * Disposable CI runtime only.
 */
if ( ! defined( 'ABSPATH' ) ) {
	throw new RuntimeException( 'WordPress is not loaded.' );
}

$worker = (string) getenv( 'MAD4B_CI_AUDIT_WORKER' );
$ready_file = (string) getenv( 'MAD4B_CI_AUDIT_READY_FILE' );
$release_file = (string) getenv( 'MAD4B_CI_AUDIT_RELEASE_FILE' );
if ( ! in_array( $worker, array( 'one', 'two' ), true )
	|| 0 !== strpos( $ready_file, '/tmp/mad4b-audit-sync-' )
	|| 0 !== strpos( $release_file, '/tmp/mad4b-audit-sync-' ) ) {
	throw new RuntimeException( 'Audit worker requires a valid worker id and bounded sync files.' );
}

if ( false === @file_put_contents( $ready_file, $worker, LOCK_EX ) ) {
	throw new RuntimeException( 'Audit worker could not publish its ready marker.' );
}
$deadline = microtime( true ) + 30.0;
while ( ! is_file( $release_file ) ) {
	if ( microtime( true ) >= $deadline ) throw new RuntimeException( 'Audit worker timed out waiting for release.' );
	usleep( 10000 );
}

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
