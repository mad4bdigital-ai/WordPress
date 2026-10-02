<?php
define( 'ABSPATH', __DIR__ );

class WP_Error {
	private $code;
	private $data;
	public function __construct( $code, $message = '', $data = null ) { $this->code = (string) $code; $this->data = $data; }
	public function get_error_code() { return $this->code; }
	public function get_error_data() { return $this->data; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $value ) ); }

final class MAD4B_SCP_Operation_Journal {
	public static $status = array();
	public static function status( $operation_id ) {
		if ( 'missing' === $operation_id ) return new WP_Error( 'missing' );
		return self::$status;
	}
}
final class MAD4B_SCP_Operation_Resume {
	public static $status = array();
	public static function status( $input ) {
		if ( isset( $input['idempotency_key'] ) && 'missing' === $input['idempotency_key'] ) return new WP_Error( 'missing' );
		return self::$status;
	}
}

require dirname( __DIR__ ) . '/includes/class-mad4b-scp-execution-state-view.php';

$check = static function ( $condition, $message, $context = null ) {
	if ( $condition ) return;
	fwrite( STDERR, 'FAIL execution-state-view: ' . $message . PHP_EOL );
	if ( null !== $context ) fwrite( STDERR, json_encode( $context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL );
	exit( 1 );
};
$base_journal = array(
	'contract' => 'mad4b.dynamic-operation-status.v1',
	'operation_id' => '11111111-1111-4111-8111-111111111111',
	'operation_binding_sha256' => str_repeat( 'a', 64 ),
	'latest_sequence' => 2,
	'journal_head_sha256' => str_repeat( 'b', 64 ),
	'lifecycle_state' => 'planned',
	'terminal_outcome' => '',
	'stale_heartbeat' => false,
	'lock_expired' => false,
	'hard_deadline_exceeded' => false,
	'orphan_candidate' => false,
);
$cases = array(
	array( 'state' => 'planned', 'outcome' => '', 'orphan' => false, 'expected' => 'PREPARED', 'terminal' => false, 'reconcile' => false ),
	array( 'state' => 'running', 'outcome' => '', 'orphan' => false, 'expected' => 'EXECUTING', 'terminal' => false, 'reconcile' => false ),
	array( 'state' => 'completed', 'outcome' => 'committed', 'orphan' => false, 'expected' => 'COMMITTED', 'terminal' => true, 'reconcile' => false ),
	array( 'state' => 'completed', 'outcome' => '', 'orphan' => false, 'expected' => 'UNKNOWN', 'terminal' => false, 'reconcile' => false ),
	array( 'state' => 'terminal_failed', 'outcome' => 'failed', 'orphan' => false, 'expected' => 'FAILED', 'terminal' => true, 'reconcile' => false ),
	array( 'state' => 'running', 'outcome' => '', 'orphan' => true, 'expected' => 'RECONCILING', 'terminal' => false, 'reconcile' => true ),
	array( 'state' => 'future_state', 'outcome' => '', 'orphan' => false, 'expected' => 'UNKNOWN', 'terminal' => false, 'reconcile' => false ),
);
foreach ( $cases as $case ) {
	$status = $base_journal;
	$status['lifecycle_state'] = $case['state'];
	$status['terminal_outcome'] = $case['outcome'];
	$status['orphan_candidate'] = $case['orphan'];
	$view = MAD4B_SCP_Execution_State_View::normalize_journal_status( $status );
	$check( is_array( $view ) && $case['expected'] === $view['canonical_state'], 'Journal mapping mismatch.', array( 'case' => $case, 'view' => $view ) );
	$check( $case['terminal'] === $view['terminal'], 'Journal terminal flag mismatch.', $view );
	$check( $case['reconcile'] === $view['reconciliation_required'], 'Journal reconciliation flag mismatch.', $view );
	$check( false === $view['blind_retry_allowed'] && ! empty( $view['read_only'] ) && empty( $view['authority_created'] ), 'Journal view widened execution authority.', $view );
}
$bad_journal = MAD4B_SCP_Execution_State_View::normalize_journal_status( array( 'contract' => 'foreign' ) );
$check( is_wp_error( $bad_journal ) && 'mad4b_execution_state_journal_contract_invalid' === $bad_journal->get_error_code(), 'Foreign Journal contract was normalized.' );

$base_resume = array(
	'contract' => 'mad4b.operation-resume-status.v1',
	'status' => 'pending',
	'claim_epoch' => 1,
	'expired' => false,
	'result_sha256' => '',
	'reconciliation_ref_present' => false,
	'reconciliation_required' => false,
	'retry_allowed' => false,
	'client_action' => 'wait_or_reconnect_without_replay',
);
$resume_cases = array(
	array( 'status' => 'completed', 'expired' => false, 'reconcile' => false, 'expected' => 'COMMITTED', 'retry' => false ),
	array( 'status' => 'released_verified_no_effect', 'expired' => true, 'reconcile' => false, 'expected' => 'PREPARED', 'retry' => true ),
	array( 'status' => 'pending', 'expired' => false, 'reconcile' => false, 'expected' => 'EXECUTING', 'retry' => false ),
	array( 'status' => 'pending', 'expired' => true, 'reconcile' => true, 'expected' => 'RECONCILING', 'retry' => false ),
	array( 'status' => 'foreign', 'expired' => false, 'reconcile' => false, 'expected' => 'UNKNOWN', 'retry' => false ),
);
foreach ( $resume_cases as $case ) {
	$status = $base_resume;
	$status['status'] = $case['status'];
	$status['expired'] = $case['expired'];
	$status['reconciliation_required'] = $case['reconcile'];
	$view = MAD4B_SCP_Execution_State_View::normalize_resume_status( $status );
	$check( is_array( $view ) && $case['expected'] === $view['canonical_state'], 'Durable resume mapping mismatch.', array( 'case' => $case, 'view' => $view ) );
	$check( $case['retry'] === $view['retry_after_replan_allowed'], 'Durable retry-after-replan flag mismatch.', $view );
	$check( false === $view['blind_retry_allowed'], 'Durable state view permitted blind mutation retry.', $view );
}

$not_started = new WP_Error( 'fixture_preflight', '', array(
	'mutation_state' => 'not_started',
	'reconciliation_required' => false,
	'target_execution_entered' => false,
	'fresh_plan_required' => true,
) );
$not_started_view = MAD4B_SCP_Execution_State_View::mutation_error( $not_started );
$check( 'FAILED' === $not_started_view['canonical_state'] && true === $not_started_view['terminal'] && false === $not_started_view['reconciliation_required'], 'Not-started mutation attempt was misclassified.', $not_started_view );

$unknown = new WP_Error( 'fixture_uncertain', '', array(
	'mutation_state' => 'unknown',
	'reconciliation_required' => true,
	'target_execution_entered' => true,
) );
$unknown_view = MAD4B_SCP_Execution_State_View::mutation_error( $unknown );
$check( 'RECONCILING' === $unknown_view['canonical_state'] && true === $unknown_view['reconciliation_required'] && false === $unknown_view['blind_retry_allowed'], 'Unknown mutation outcome did not require reconciliation.', $unknown_view );

$unmapped_error = MAD4B_SCP_Execution_State_View::mutation_error( new WP_Error( 'fixture_unmapped' ) );
$check( 'UNKNOWN' === $unmapped_error['canonical_state'] && ! empty( $unmapped_error['unmapped_source_state'] ), 'Unmapped mutation error was promoted to a known state.', $unmapped_error );

MAD4B_SCP_Operation_Journal::$status = $base_journal;
$delegated_operation = MAD4B_SCP_Execution_State_View::operation( $base_journal['operation_id'] );
$check( 'PREPARED' === $delegated_operation['canonical_state'], 'Operation Journal delegation failed.', $delegated_operation );
$check( is_wp_error( MAD4B_SCP_Execution_State_View::operation( 'missing' ) ), 'Operation Journal source error was swallowed.' );

MAD4B_SCP_Operation_Resume::$status = $base_resume;
$delegated_resume = MAD4B_SCP_Execution_State_View::idempotency( array(
	'scope_key' => str_repeat( 'a', 64 ),
	'idempotency_key' => 'fixture',
	'request_sha256' => str_repeat( 'b', 64 ),
) );
$check( 'EXECUTING' === $delegated_resume['canonical_state'], 'Durable resume delegation failed.', $delegated_resume );
$check( is_wp_error( MAD4B_SCP_Execution_State_View::idempotency( array( 'idempotency_key' => 'missing' ) ) ), 'Durable resume source error was swallowed.' );

echo "mad4b.execution-state-view.v1: PASS\n";
