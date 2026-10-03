<?php

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['mad4b_finalization_events'] = array();
$GLOBALS['mad4b_finalize_ticket_error'] = false;
$GLOBALS['mad4b_canary_persist_error'] = false;
$GLOBALS['mad4b_terminal_audit_error'] = false;

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) { return true; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $value ) ); }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function apply_filters( $hook, $value ) { return $value; }
function trailingslashit( $value ) { return rtrim( (string) $value, "/\\" ) . '/'; }
function wp_normalize_path( $value ) { return str_replace( '\\', '/', (string) $value ); }
function wp_mkdir_p( $target ) {
	if ( is_dir( $target ) ) return true;
	return @mkdir( $target, 0700, true ) || is_dir( $target );
}

$mad4b_fixture_keyring = rtrim( sys_get_temp_dir(), "/\\" ) . '/mad4b-provider-canary-finalization-' . getmypid();
define( 'MAD4B_SCP_CRYPTO_KEYRING_DIR', $mad4b_fixture_keyring );
register_shutdown_function( static function () use ( $mad4b_fixture_keyring ) {
	if ( ! is_dir( $mad4b_fixture_keyring ) ) return;
	$items = scandir( $mad4b_fixture_keyring );
	if ( is_array( $items ) ) foreach ( $items as $item ) {
		if ( '.' === $item || '..' === $item ) continue;
		$path = $mad4b_fixture_keyring . '/' . $item;
		if ( is_file( $path ) || is_link( $path ) ) @unlink( $path );
	}
	@rmdir( $mad4b_fixture_keyring );
} );

class WP_Error {
	private $code;
	private $message;
	private $data;
	public function __construct( $code = '', $message = '', $data = array() ) { $this->code = (string) $code; $this->message = (string) $message; $this->data = $data; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}

final class MAD4B_SCP_Audit {
	public static function record( $ability, $summary, $status = 'ok' ) {
		$GLOBALS['mad4b_finalization_events'][] = 'authorization-audit:' . (string) $status;
		if ( 'completed' === (string) $status && ! empty( $GLOBALS['mad4b_terminal_audit_error'] ) ) {
			return new WP_Error( 'mad4b_audit_append_failed', 'Synthetic terminal audit persistence failure.' );
		}
		return array(
			'contract' => 'mad4b.audit.v2',
			'event_id' => '33333333-3333-4333-8333-333333333333',
			'entry_hash' => str_repeat( 'a', 64 ),
		);
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

require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-crypto-profile.php';
require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-execution-evidence-policy.php';
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
	'context_receipt_sha256' => str_repeat( 'b', 64 ),
	'capability_descriptor_sha256' => str_repeat( 'c', 64 ),
	'policy_decision_sha256' => str_repeat( 'd', 64 ),
);
$provider_result = array( 'contract' => 'mad4b.provider-canary-execution.v1' );

$GLOBALS['mad4b_finalization_events'] = array();
$result = MAD4B_SCP_Authorization::finalize_execution_claim( $claim, $provider_result );
mad4b_finalize_same( true, $result, 'successful canary finalization should succeed' );
mad4b_finalize_same( array( 'ticket:used', 'canary:persist', 'authorization-audit:completed', 'authorization-audit:completed' ), $GLOBALS['mad4b_finalization_events'], 'authorization must consume approval, persist provider correlation, commit terminal evidence, then append the unified execution receipt' );

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
mad4b_finalize_same( true, $result, 'non-approved governed execution should persist terminal success evidence without ticket/canary correlation' );
mad4b_finalize_same( array( 'authorization-audit:completed', 'authorization-audit:completed' ), $GLOBALS['mad4b_finalization_events'], 'no approval still requires durable terminal success evidence and the unified execution receipt' );

$GLOBALS['mad4b_finalization_events'] = array();
$GLOBALS['mad4b_terminal_audit_error'] = true;
$result = MAD4B_SCP_Authorization::finalize_execution_claim( $claim, $provider_result );
mad4b_finalize_assert( is_wp_error( $result ), 'terminal audit persistence failure after provider side effect must suppress success' );
mad4b_finalize_same( 'mad4b_execution_terminal_evidence_persist_failed', $result->get_error_code(), 'terminal audit persistence failure code must be canonical' );
$data = $result->get_error_data();
mad4b_finalize_assert( is_array( $data ) && ! empty( $data['reconciliation_required'] ) && empty( $data['blind_retry_allowed'] ), 'terminal audit failure must require reconciliation and prohibit blind retry' );
mad4b_finalize_same( array( 'ticket:used', 'canary:persist', 'authorization-audit:completed' ), $GLOBALS['mad4b_finalization_events'], 'terminal audit failure must occur only after approval consumption and provider correlation' );
$GLOBALS['mad4b_terminal_audit_error'] = false;

echo "MAD4B provider canary authorization finalization contract passed.\n";
