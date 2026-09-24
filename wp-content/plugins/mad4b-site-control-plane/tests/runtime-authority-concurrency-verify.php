<?php
/** Exact grant-row stability proof around concurrent authority requests. */
if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }

$fail = static function ( $message ) {
	fwrite( STDERR, '[MAD4B authority concurrency verify] ' . $message . PHP_EOL );
	exit( 1 );
};

if ( ! current_user_can( 'manage_options' ) ) $fail( 'Administrator context is required.' );
$phase = getenv( 'MAD4B_AUTHORITY_CONCURRENCY_PHASE' );
$phase = is_string( $phase ) ? strtolower( trim( $phase ) ) : '';
if ( ! in_array( $phase, array( 'capture', 'verify' ), true ) ) $fail( 'MAD4B_AUTHORITY_CONCURRENCY_PHASE must be capture or verify.' );

$file = getenv( 'MAD4B_AUTHORITY_CONCURRENCY_SNAPSHOT_FILE' );
$file = is_string( $file ) && '' !== trim( $file ) ? trim( $file ) : '/tmp/mad4b-authority-concurrency.json';

$authority = MAD4B_SCP_Staging_Write_Authority::status();
if ( empty( $authority['ready'] ) || empty( $authority['agent_public_id'] ) ) $fail( 'Persisted authority is not ready.' );
$agent = MAD4B_SCP_Agent_Registry::get_agent_by_public_id( $authority['agent_public_id'] );
if ( ! is_array( $agent ) || empty( $agent['id'] ) ) $fail( 'Canonical governed-write agent is missing.' );

$rows = MAD4B_SCP_Agent_Registry::grants_for_agent( $agent['id'], 'mad4b-write' );
foreach ( $rows as &$row ) {
	if ( is_array( $row ) ) ksort( $row, SORT_STRING );
}
unset( $row );
usort( $rows, static function ( $a, $b ) {
	return strcmp(
		wp_json_encode( $a, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
		wp_json_encode( $b, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
	);
} );

$payload = array(
	'contract' => 'mad4b.authority-concurrency-grant-stability.v1',
	'agent_public_id' => (string) $agent['public_id'],
	'rows' => $rows,
	'hash' => hash( 'sha256', wp_json_encode( $rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ),
);

if ( 'capture' === $phase ) {
	if ( false === file_put_contents( $file, wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) ) $fail( 'Unable to save concurrency baseline.' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	echo "mad4b.authority-concurrency-grant-stability.v1: CAPTURED {$payload['hash']}\n";
	return;
}

if ( ! is_file( $file ) ) $fail( 'Concurrency baseline is missing.' );
$raw = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$before = is_string( $raw ) ? json_decode( $raw, true ) : null;
if ( ! is_array( $before ) || 'mad4b.authority-concurrency-grant-stability.v1' !== ( $before['contract'] ?? '' ) ) $fail( 'Concurrency baseline is invalid.' );
if ( ! hash_equals( (string) $before['agent_public_id'], (string) $payload['agent_public_id'] ) ) $fail( 'Canonical agent changed during concurrent requests.' );
if ( ! hash_equals( (string) $before['hash'], (string) $payload['hash'] ) || $before['rows'] !== $payload['rows'] ) {
	$fail( 'Exact mad4b-write grant rows changed during concurrent authority requests. before=' . (string) $before['hash'] . ' after=' . $payload['hash'] );
}

$live = MAD4B_SCP_Live_Truth::current_authority_status();
if ( empty( $live['ready'] ) || empty( $live['runtime_reconciled'] ) ) $fail( 'Authority is not ready/reconciled after concurrency proof.' );
if ( isset( $live['exact_grants_existing'], $live['write_tool_count'] ) && (int) $live['exact_grants_existing'] !== (int) $live['write_tool_count'] ) {
	$fail( 'Exact grant count does not match current write-tool count after concurrency proof.' );
}

echo "mad4b.authority-concurrency-grant-stability.v1: VERIFIED {$payload['hash']}\n";
