<?php
/**
 * Extends the real MCP governed-execution regression through durable Live
 * Acceptance reconstruction. The base fixture performs the authoritative
 * execute -> independent replay denial -> undo sequence against the real MCP
 * Adapter route and leaves its evidence variables in this include scope.
 */
require __DIR__ . '/runtime-mcp-governed-execution-fence.php';

$check( class_exists( 'MAD4B_SCP_Live_Acceptance_Reconciler' ), 'Live Acceptance Reconciler unavailable after real MCP cycle' );
$check( class_exists( 'MAD4B_SCP_Live_Acceptance_Finalizer' ), 'Live Acceptance Finalizer unavailable after real MCP cycle' );
$check( ! empty( $mutation['mutation_id'] ), 'real MCP cycle did not expose mutation_id for reconstruction' );
$check( ! empty( $ticket['ticket_id'] ), 'real MCP cycle did not expose execution ticket for reconstruction' );
$check( ! empty( $undo_ticket['ticket_id'] ), 'real MCP cycle did not expose undo ticket for reconstruction' );
$check( isset( $replay_denials[0]['sequence'] ), 'real MCP cycle did not expose replay-denial sequence for reconstruction' );

$mutation_id = strtolower( (string) $mutation['mutation_id'] );
$execution_sequence = 0;
$undo_sequence = 0;
$replay_sequence = (int) $replay_denials[0]['sequence'];

foreach ( MAD4B_SCP_Audit::tail( 200 ) as $audit_event ) {
	if ( ! is_array( $audit_event ) ) continue;
	$summary = isset( $audit_event['summary'] ) && is_array( $audit_event['summary'] ) ? $audit_event['summary'] : array();
	if ( empty( $summary['mutation_id'] ) || ! hash_equals( $mutation_id, strtolower( (string) $summary['mutation_id'] ) ) ) continue;
	$sequence = isset( $audit_event['sequence'] ) ? (int) $audit_event['sequence'] : 0;
	$ability = isset( $audit_event['ability'] ) ? (string) $audit_event['ability'] : '';
	$status = isset( $audit_event['status'] ) ? (string) $audit_event['status'] : '';

	if ( 'mad4b/mutation-undo' === $ability && 'ok' === $status ) {
		$undo_sequence = max( $undo_sequence, $sequence );
		continue;
	}
	if ( 'ok' !== $status || 0 === strpos( $ability, 'mad4b/live-acceptance-' ) || 0 === strpos( $ability, 'mad4b/authorization:' ) ) continue;
	if ( empty( $summary['before_sha256'] ) || empty( $summary['after_sha256'] ) ) continue;
	if ( ! hash_equals( (string) $mutation['before_sha256'], (string) $summary['before_sha256'] ) ) continue;
	if ( ! hash_equals( (string) $mutation['after_sha256'], (string) $summary['after_sha256'] ) ) continue;
	$execution_sequence = max( $execution_sequence, $sequence );
}

$check( $execution_sequence > 0, 'durable execution event was not found for the exact real MCP mutation' );
$check( $replay_sequence > 0, 'durable replay-denial event was not sequenced' );
$check( $undo_sequence > 0, 'durable undo event was not found for the exact real MCP mutation' );
$check(
	$execution_sequence < $replay_sequence && $replay_sequence < $undo_sequence,
	'real MCP durable ordering must be execute < replay denial < undo: ' . wp_json_encode( array(
		'execution_sequence' => $execution_sequence,
		'replay_sequence' => $replay_sequence,
		'undo_sequence' => $undo_sequence,
	) )
);

// Remove the observer optimization so this assertion can only pass through the
// Reconciler's authoritative reconstruction from append-only audit, mutation
// records and exact candidate-bound used approval tickets.
delete_option( MAD4B_SCP_Live_Acceptance_Finalizer::LEDGER_OPTION );
$acceptance = MAD4B_SCP_Live_Acceptance_Reconciler::mutation_acceptance_status();
$check( is_array( $acceptance ) && ! empty( $acceptance['ready'] ), 'real MCP cycle did not reconstruct mutation_acceptance.ready=true: ' . wp_json_encode( $acceptance ) );
$check( 'durable_authoritative_reconstruction' === ( isset( $acceptance['evidence_source'] ) ? (string) $acceptance['evidence_source'] : '' ), 'real MCP acceptance did not use durable authoritative reconstruction: ' . wp_json_encode( $acceptance ) );
$check( hash_equals( $mutation_id, strtolower( (string) ( isset( $acceptance['mutation_id'] ) ? $acceptance['mutation_id'] : '' ) ) ), 'reconstructed acceptance is not bound to the exact real MCP mutation' );
$check( hash_equals( strtolower( (string) $ticket['ticket_id'] ), strtolower( (string) ( isset( $acceptance['approval_ticket_id'] ) ? $acceptance['approval_ticket_id'] : '' ) ) ), 'reconstructed acceptance is not bound to the exact execution ticket' );
$check( hash_equals( strtolower( (string) $undo_ticket['ticket_id'] ), strtolower( (string) ( isset( $acceptance['undo_approval_ticket_id'] ) ? $acceptance['undo_approval_ticket_id'] : '' ) ) ), 'reconstructed acceptance is not bound to the exact undo ticket' );
$check( empty( $acceptance['first_reconstruction_failure'] ), 'ready durable reconstruction retained a failure marker: ' . wp_json_encode( $acceptance ) );

// Prove the permission-time denial bridge is exactly-once inside one logical
// request. Reset only the request-local approval overlay left by the undo, then
// issue the same already-used execution ticket twice under one new request_id.
// The first call must emit the canonical replay event; the fence must cache that
// terminal permission result so the second permission evaluation cannot append
// another event.
$reflection = new ReflectionClass( 'MAD4B_SCP_Identity_Context' );
$property = $reflection->getProperty( 'request_approval_ticket_id' );
$property->setAccessible( true );
$property->setValue( null, '' );
$approval_ticket_id = $ticket['ticket_id'];
$request_id = 'ci-mcp-fence-request-replay-audit-once';
$audit_before_exactly_once = MAD4B_SCP_Audit::tail( 200 );
$exactly_once_floor = 0;
foreach ( $audit_before_exactly_once as $audit_event ) {
	if ( is_array( $audit_event ) && isset( $audit_event['sequence'] ) ) $exactly_once_floor = max( $exactly_once_floor, (int) $audit_event['sequence'] );
}

$replay_once_a = $dispatch( array( 'jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/call', 'params' => array( 'name' => 'media-update-metadata', 'arguments' => $call_input ) ), $session_id );
$replay_once_b = $dispatch( array( 'jsonrpc' => '2.0', 'id' => 8, 'method' => 'tools/call', 'params' => array( 'name' => 'media-update-metadata', 'arguments' => $call_input ) ), $session_id );
foreach ( array( $replay_once_a, $replay_once_b ) as $replay_once_response ) {
	$replay_once_json = wp_json_encode( $replay_once_response->get_data(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	$check( false !== strpos( $replay_once_json, 'replay' ) || false !== strpos( $replay_once_json, 'terminal or already claimed' ), 'request-local repeated replay did not preserve canonical denial: ' . $replay_once_json );
}

$exactly_once_events = array();
foreach ( MAD4B_SCP_Audit::tail( 200 ) as $audit_event ) {
	if ( ! is_array( $audit_event ) || ( isset( $audit_event['sequence'] ) ? (int) $audit_event['sequence'] : 0 ) <= $exactly_once_floor ) continue;
	if ( 'denied' !== ( isset( $audit_event['status'] ) ? (string) $audit_event['status'] : '' ) ) continue;
	if ( 'mad4b/authorization:media/update-metadata' !== ( isset( $audit_event['ability'] ) ? (string) $audit_event['ability'] : '' ) ) continue;
	$summary = isset( $audit_event['summary'] ) && is_array( $audit_event['summary'] ) ? $audit_event['summary'] : array();
	if ( 'mad4b_approval_replay_denied' !== ( isset( $summary['reason_code'] ) ? (string) $summary['reason_code'] : '' ) ) continue;
	if ( ! hash_equals( strtolower( (string) $ticket['ticket_id'] ), strtolower( (string) ( isset( $summary['approval_ticket_id'] ) ? $summary['approval_ticket_id'] : '' ) ) ) ) continue;
	if ( $request_id !== ( isset( $summary['request_id'] ) ? (string) $summary['request_id'] : '' ) ) continue;
	$exactly_once_events[] = $audit_event;
}
$check( 1 === count( $exactly_once_events ), 'same logical replay request must append exactly one durable denial event: ' . wp_json_encode( $exactly_once_events ) );

echo "mad4b.site-control-plane.mcp-governed-execution-fence-reconciler.v1: PASS\n";
