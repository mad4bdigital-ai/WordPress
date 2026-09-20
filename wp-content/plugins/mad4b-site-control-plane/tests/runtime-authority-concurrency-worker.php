<?php
/** Concurrent authority read/reconcile worker for disposable CI only. */
if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }

$fail = static function ( $message ) {
	fwrite( STDERR, '[MAD4B authority concurrency worker] ' . $message . PHP_EOL );
	exit( 1 );
};

if ( ! current_user_can( 'manage_options' ) ) $fail( 'Administrator context is required.' );
$mode = getenv( 'MAD4B_AUTHORITY_CONCURRENCY_MODE' );
$mode = is_string( $mode ) ? strtolower( trim( $mode ) ) : '';

if ( 'read' === $mode ) {
	$authority = MAD4B_SCP_Live_Truth::current_authority_status();
	$cert = MAD4B_SCP_Live_Truth::current_write_certification();
	if ( empty( $authority['ready'] ) || empty( $authority['runtime_reconciled'] ) ) $fail( 'Concurrent read observed unreconciled authority.' );
	if ( empty( $cert['ready'] ) ) $fail( 'Concurrent read observed blocked write certification.' );
	echo "mad4b.authority-concurrency-worker.v1: READ PASS\n";
	return;
}

if ( 'reconcile' === $mode ) {
	$status = MAD4B_SCP_Staging_Write_Authority::reconcile();
	if ( empty( $status['ready'] ) ) $fail( 'Explicit concurrent reconcile did not finish ready: ' . wp_json_encode( $status['grant_blockers'] ?? array() ) );
	if ( ! empty( $status['stale_allow_grants_revoked'] ) ) $fail( 'Explicit concurrent reconcile revoked stable grants.' );
	if ( ! empty( $status['grant_blockers'] ) ) $fail( 'Explicit concurrent reconcile reported grant blockers.' );
	echo "mad4b.authority-concurrency-worker.v1: RECONCILE PASS\n";
	return;
}

$fail( 'MAD4B_AUTHORITY_CONCURRENCY_MODE must be read or reconcile.' );
