<?php
/**
 * Extends the real MCP governed-execution regression through durable Live
 * Acceptance reconstruction. The base fixture performs the authoritative
 * execute -> independent replay denial -> undo sequence against the real MCP
 * Adapter route and leaves its evidence variables in this include scope.
 *
 * The execution-fence fixture is intentionally tenant-neutral. ETG Live
 * Acceptance remains deployment-specific, so a generic enrolled Site Profile
 * must prove the durable mutation sequence while being rejected as ETG
 * acceptance evidence rather than being falsely attributed to ETG.
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

// Remove the observer optimization so the result can only come from the
// Reconciler's authoritative reconstruction over append-only audit, mutation
// records and exact candidate-bound used approval tickets.
delete_option( MAD4B_SCP_Live_Acceptance_Finalizer::LEDGER_OPTION );
$acceptance = MAD4B_SCP_Live_Acceptance_Reconciler::mutation_acceptance_status();
$check( is_array( $acceptance ) && ! empty( $acceptance['ready'] ), 'generic enrolled Site Profile did not reconstruct mutation_acceptance.ready=true: ' . wp_json_encode( $acceptance ) );
$check( 'durable_authoritative_reconstruction' === ( isset( $acceptance['evidence_source'] ) ? (string) $acceptance['evidence_source'] : '' ), 'generic Site Profile acceptance did not use durable authoritative reconstruction: ' . wp_json_encode( $acceptance ) );
$check( hash_equals( $mutation_id, strtolower( (string) ( isset( $acceptance['mutation_id'] ) ? $acceptance['mutation_id'] : '' ) ) ), 'reconstructed acceptance is not bound to the exact real MCP mutation' );
$check( hash_equals( strtolower( (string) $ticket['ticket_id'] ), strtolower( (string) ( isset( $acceptance['approval_ticket_id'] ) ? $acceptance['approval_ticket_id'] : '' ) ) ), 'reconstructed acceptance is not bound to the exact execution ticket' );
$check( hash_equals( strtolower( (string) $undo_ticket['ticket_id'] ), strtolower( (string) ( isset( $acceptance['undo_approval_ticket_id'] ) ? $acceptance['undo_approval_ticket_id'] : '' ) ) ), 'reconstructed acceptance is not bound to the exact undo ticket' );
$check( empty( $acceptance['first_reconstruction_failure'] ), 'ready durable reconstruction retained a failure marker: ' . wp_json_encode( $acceptance ) );

// The real MCP cycle above proves that an independent replay emits exactly one
// canonical durable audit event. Separately prove the Execution Fence's
// request-local guarantee without depending on MCP Adapter session caching:
// repeated evaluation of the same replay-denial permission callback under one
// logical request must invoke the inner callback once and reuse that terminal
// result thereafter.
$reflection = new ReflectionClass( 'MAD4B_SCP_Identity_Context' );
$property = $reflection->getProperty( 'request_approval_ticket_id' );
$property->setAccessible( true );
$property->setValue( null, '' );
$approval_ticket_id = $ticket['ticket_id'];
$request_id = 'ci-mcp-fence-request-permission-dedupe';
$permission_calls = 0;
$permission_error = new WP_Error( 'mad4b_approval_replay_denied', 'Approval ticket is terminal or already claimed; replay is denied.' );
$fenced_args = MAD4B_SCP_Execution_Fence::wrap_governed_write( array(
	'execute_callback' => static function ( $input = null ) { return true; },
	'permission_callback' => static function ( $input = null ) use ( &$permission_calls, $permission_error ) {
		++$permission_calls;
		return $permission_error;
	},
	'meta' => array(
		'annotations' => array( 'readonly' => false ),
		'mcp' => array( 'mad4b_execution_boundary' => MAD4B_SCP_Authorization::EXECUTION_BOUNDARY_CONTRACT ),
	),
), 'media/update-metadata' );
$check( isset( $fenced_args['permission_callback'] ) && is_callable( $fenced_args['permission_callback'] ), 'execution fence did not expose wrapped permission callback for replay dedupe proof' );
$permission_a = call_user_func( $fenced_args['permission_callback'], $call_input );
$permission_b = call_user_func( $fenced_args['permission_callback'], $call_input );
$check( is_wp_error( $permission_a ) && 'mad4b_approval_replay_denied' === $permission_a->get_error_code(), 'first fenced permission denial lost canonical replay error' );
$check( is_wp_error( $permission_b ) && 'mad4b_approval_replay_denied' === $permission_b->get_error_code(), 'cached fenced permission denial lost canonical replay error' );
$check( 1 === $permission_calls, 'same logical request evaluated replay permission more than once: ' . $permission_calls );

echo "mad4b.site-control-plane.mcp-governed-execution-fence-reconciler.v2: PASS\n";
