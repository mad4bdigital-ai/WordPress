<?php

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['mad4b_finalization_events'] = array();
$GLOBALS['mad4b_finalize_ticket_error'] = false;
$GLOBALS['mad4b_canary_persist_error'] = false;

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) { return true; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $value ) ); }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function apply_filters( $hook, $value ) { return $value; }

class WP_Error {
	private $code;
	private $message;
	public function __construct( $code = '', $message = '' ) { $this->code = (string) $code; $this->message = (string) $message; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}

final class MAD4B_SCP_Audit {
	public static function record( $ability, $summary, $status = 'ok' ) {
		$GLOBALS['mad4b_finalization_events'][] = 'authorization-audit:' . (string) $status;
		return true;
	}
}

final class MAD4B_SCP_Approval_Tickets {
	public static function finalize_claim( $ticket_id, $status ) {
		$GLOBALS['mad4b_finalization_events'][] = 'ticket:' . (string) $status;
		if ( ! empty( $GLOBALS['mad4b_finalize_ticket_error'] ) ) return new WP_Error( 'mad4b_test_finalize_failed', 'Synthetic ticket finalization failure.' );
		return array( 'ticket_id' => (string) $ticket_id, 'status' => (string) $status );
	}
}

final class MAD4B_SCP_Provider_Canary_Execution {
	public static function persist_authorized_evidence( array $claim, $result ) {
		$GLOBALS['mad4b_finalization_events'][] = 'canary:persist';
		if ( ! empty( $GLOBALS['mad4b_canary_persist_error'] ) ) {
			return new WP_Error( 'mad4b_provider_canary_authorized_evidence_persist_failed', 'Synthetic post-side-effect evidence failure.' );
		}
		return array( 'durable' => true, 'approval_ticket_id' => isset( $claim['approval_ticket_id'] ) ? $claim['approval_ticket_id'] : '' );
	}
}

require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-authorization.php';

function mad4b_finalize_assert( $condition, $message ) {
	if ( ! $condition ) { fwrite( STDERR, "FAIL: {$message}\n" ); exit( 1 ); }
}
function mad4b_finalize_same( $expected, $actual, $message ) {
	if ( $expected !== $actual ) {
		fwrite( STDERR, "FAIL: {$message} expected=" . var_export( $expected, true ) . " actual=" . var_export( $actual, true ) . "\n" );
		exit( 1 );
	}
}

$claim = array(
	'approval_required' => true,
	'approval_ticket_id' => '11111111-1111-4111-8111-111111111111',
	'ability' => 'mad4b/provider-canary-execute',
	'request_id' => 'req-finalize-1',
	'agent_public_id' => 'agent-finalize-1',
	'grant_id' => 91,
	'server_id' => 'mad4b-write',
	'provider' => 'core',
	'impact' => 'high',
);
$provider_result = array( 'contract' => 'mad4b.provider-canary-execution.v1' );

$GLOBALS['mad4b_finalization_events'] = array();
$result = MAD4B_SCP_Authorization::finalize_execution_claim( $claim, $provider_result );
mad4b_finalize_same( true, $result, 'successful canary finalization should succeed' );
mad4b_finalize_same( array( 'ticket:used', 'canary:persist' ), $GLOBALS['mad4b_finalization_events'], 'authorization must consume approval before durable canary correlation' );

$GLOBALS['mad4b_finalization_events'] = array();
$GLOBALS['mad4b_canary_persist_error'] = true;
$result = MAD4B_SCP_Authorization::finalize_execution_claim( $claim, $provider_result );
mad4b_finalize_assert( is_wp_error( $result ), 'post-side-effect evidence persistence failure must propagate' );
mad4b_finalize_same( 'mad4b_provider_canary_authorized_evidence_persist_failed', $result->get_error_code(), 'post-side-effect evidence error code must remain canonical' );
mad4b_finalize_same( 'ticket:used', $GLOBALS['mad4b_finalization_events'][0], 'approval must already be terminal used before evidence persistence is attempted' );
mad4b_finalize_same( 'canary:persist', $GLOBALS['mad4b_finalization_events'][1], 'canary correlation is attempted exactly after ticket finalization' );
mad4b_finalize_same( 3, count( $GLOBALS['mad4b_finalization_events'] ), 'terminal correlation failure should add only one authorization audit after the single persistence attempt' );
mad4b_finalize_same( 'authorization-audit:failed', $GLOBALS['mad4b_finalization_events'][2], 'terminal correlation failure must be audited without reopening approval' );
$GLOBALS['mad4b_canary_persist_error'] = false;

$GLOBALS['mad4b_finalization_events'] = array();
$execution_error = new WP_Error( 'provider_failed', 'Provider mutation failed.' );
$result = MAD4B_SCP_Authorization::finalize_execution_claim( $claim, $execution_error );
mad4b_finalize_same( true, $result, 'failed provider execution should still finalize its one-time claim' );
mad4b_finalize_same( array( 'ticket:failed' ), $GLOBALS['mad4b_finalization_events'], 'failed execution must terminalize ticket and must not create authorized canary evidence' );

$GLOBALS['mad4b_finalization_events'] = array();
$GLOBALS['mad4b_finalize_ticket_error'] = true;
$result = MAD4B_SCP_Authorization::finalize_execution_claim( $claim, $provider_result );
mad4b_finalize_assert( is_wp_error( $result ), 'ticket finalization failure must propagate' );
mad4b_finalize_same( 'mad4b_test_finalize_failed', $result->get_error_code(), 'ticket finalization failure remains primary' );
mad4b_finalize_same( array( 'ticket:used', 'authorization-audit:failed' ), $GLOBALS['mad4b_finalization_events'], 'canary correlation must never run unless ticket finalization succeeded' );
$GLOBALS['mad4b_finalize_ticket_error'] = false;

$GLOBALS['mad4b_finalization_events'] = array();
$no_approval = $claim;
$no_approval['approval_required'] = false;
$no_approval['approval_ticket_id'] = '';
$result = MAD4B_SCP_Authorization::finalize_execution_claim( $no_approval, $provider_result );
mad4b_finalize_same( true, $result, 'non-approved execution path should remain a no-op for approval finalization' );
mad4b_finalize_same( array(), $GLOBALS['mad4b_finalization_events'], 'no approval means no ticket or canary correlation side effects' );

echo "MAD4B provider canary authorization finalization contract passed.\n";
