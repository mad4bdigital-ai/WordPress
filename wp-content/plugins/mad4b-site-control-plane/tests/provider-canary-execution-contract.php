<?php

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['mad4b_registered_ability'] = array();
$GLOBALS['mad4b_policy_mutate'] = true;
$GLOBALS['mad4b_canary_mode'] = 'canary';
$GLOBALS['mad4b_target_mounted'] = false;
$GLOBALS['mad4b_adapter_opt_in'] = true;
$GLOBALS['mad4b_adapter_calls'] = 0;
$GLOBALS['mad4b_audit'] = array();
$GLOBALS['mad4b_audit_sequence'] = 0;
$GLOBALS['mad4b_audit_fail_status'] = '';

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) { return true; }
function apply_filters( $hook, $value ) { return $value; }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function current_user_can( $capability ) { return true; }
function get_current_user_id() { return 1; }
function wp_get_environment_type() { return 'staging'; }
function home_url( $path = '/' ) { return 'https://provider-canary.test' . $path; }
function site_url( $path = '/' ) { return home_url( $path ); }
function wp_parse_url( $url ) { return parse_url( $url ); }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function wp_has_ability( $name ) { return false; }
function wp_register_ability( $name, $args ) { $GLOBALS['mad4b_registered_ability'][ $name ] = $args; return true; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }

class WP_Error {
	private $code;
	private $message;
	public $data;
	public function __construct( $code = '', $message = '', $data = array() ) { $this->code = $code; $this->message = $message; $this->data = $data; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}

final class MAD4B_SCP_Policy {
	public static function can_mutate() { return ! empty( $GLOBALS['mad4b_policy_mutate'] ); }
}
final class MAD4B_SCP_Site_Profile {
	public static function current_environment() { return 'staging'; }
	public static function current_host() { return 'provider-canary.test'; }
	public static function nonproduction_governed( $feature = '' ) { return 'write' === (string) $feature; }
}
final class MAD4B_SCP_Staging_Write_Authority {
	const APPROVAL_INPUT_KEY = '_mad4b_approval_ticket_id';
	public static function eligible() { return true; }
}
final class MAD4B_SCP_Live_Acceptance_Observer {
	public static function build_provenance_status() {
		return array(
			'manifest_present' => true,
			'manifest_valid' => true,
			'runtime_manifest_match' => true,
			'stale' => false,
			'source_commit_sha' => str_repeat( 'a', 40 ),
			'build_fingerprint' => str_repeat( 'b', 64 ),
		);
	}
}
final class FakeCanaryAdapter {
	public function provider_key() { return 'bit_pi'; }
	public function supports_canary_execution( $ability ) { return ! empty( $GLOBALS['mad4b_adapter_opt_in'] ) && 'bitflows/run-flow' === $ability; }
	public function execute_canary( $ability, array $input ) {
		++$GLOBALS['mad4b_adapter_calls'];
		return array( 'flow_id' => isset( $input['flow_id'] ) ? (int) $input['flow_id'] : 0, 'queued' => true, 'secret_provider_detail' => 'must-not-leak' );
	}
	public function canary_result_summary( $ability, $result ) {
		return array(
			'flow_id' => is_array( $result ) && isset( $result['flow_id'] ) ? (int) $result['flow_id'] : 0,
			'queued' => is_array( $result ) && ! empty( $result['queued'] ),
		);
	}
}
final class MAD4B_SCP_Adapter_Registry {
	private static $instance;
	private $adapter;
	public static function instance() { if ( ! self::$instance ) self::$instance = new self(); return self::$instance; }
	public function __construct() { $this->adapter = new FakeCanaryAdapter(); }
	public function register_defaults() {}
	public function all() { return array( $this->adapter ); }
}
final class MAD4B_SCP_Servers {
	public static function ability_is_mounted( $server, $ability ) { return ! empty( $GLOBALS['mad4b_target_mounted'] ) && 'mad4b-write' === $server && 'bitflows/run-flow' === $ability; }
}
final class MAD4B_SCP_Provider_Compatibility_Certification {
	const ACTIVATION_CANARY = 'canary';
	public static function supports_provider( $provider ) { return 'bit_pi' === $provider; }
	public static function ability_status( $provider, $ability, $adapter = null ) {
		$mode = $GLOBALS['mad4b_canary_mode'];
		$behavioral = array(
			'state' => 'verified',
			'behavioral_verified' => true,
			'accepted_receipt' => array( 'evidence_digest' => str_repeat( 'e', 64 ) ),
		);
		$stage = 'canary';
		$eligible = true;
		if ( 'shadow' === $mode ) { $stage = 'shadow'; $eligible = false; }
		if ( 'missing_behavioral' === $mode ) $behavioral = array( 'state' => 'missing', 'behavioral_verified' => false, 'accepted_receipt' => array() );
		return array(
			'capability_id' => 'flow.execute',
			'risk' => 'high_risk_write',
			'activation_stage' => $stage,
			'canary_eligible' => $eligible,
			'write_eligible' => false,
			'artifact' => array( 'runtime_artifact_fingerprint' => str_repeat( 'c', 64 ) ),
			'capability_contract_digest' => str_repeat( 'd', 64 ),
			'behavioral_evidence' => $behavioral,
		);
	}
}
final class MAD4B_SCP_Audit {
	public static function storage_status() { return array( 'ready' => true, 'backend' => 'test_append_only' ); }
	public static function record( $ability, $summary, $status = 'ok' ) {
		if ( '' !== $GLOBALS['mad4b_audit_fail_status'] && $GLOBALS['mad4b_audit_fail_status'] === (string) $status ) {
			return new WP_Error( 'mad4b_test_audit_failure', 'Synthetic append-only audit failure.' );
		}
		++$GLOBALS['mad4b_audit_sequence'];
		$sequence = (int) $GLOBALS['mad4b_audit_sequence'];
		$entry = array(
			'event_id' => 'audit-' . $sequence,
			'sequence' => $sequence,
			'entry_hash' => hash( 'sha256', (string) $ability . "\0" . (string) $status . "\0" . $sequence . "\0" . json_encode( $summary ) ),
		);
		$GLOBALS['mad4b_audit'][] = array( $ability, $summary, $status, $entry );
		return $entry;
	}
}

require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-provider-canary-execution.php';
require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-impact-policy.php';

function expect_true( $condition, $message ) { if ( ! $condition ) { fwrite( STDERR, "FAIL: {$message}\n" ); exit( 1 ); } }
function expect_same( $expected, $actual, $message ) { if ( $expected !== $actual ) { fwrite( STDERR, "FAIL: {$message} expected=" . var_export( $expected, true ) . " actual=" . var_export( $actual, true ) . "\n" ); exit( 1 ); } }
function expect_error( $value, $code, $message ) { expect_true( is_wp_error( $value ), $message . ' must return WP_Error' ); expect_same( $code, $value->get_error_code(), $message ); }

function valid_canary_input() {
	return array(
		'provider_id' => 'bit_pi',
		'capability_id' => 'flow.execute',
		'target_ability' => 'bitflows/run-flow',
		'expected_candidate_sha' => str_repeat( 'a', 40 ),
		'expected_build_fingerprint' => str_repeat( 'b', 64 ),
		'expected_artifact_fingerprint' => str_repeat( 'c', 64 ),
		'expected_capability_contract_digest' => str_repeat( 'd', 64 ),
		'expected_behavioral_evidence_digest' => str_repeat( 'e', 64 ),
		'target_input' => array( 'flow_id' => 41, 'expected_flow_sha256' => str_repeat( 'f', 64 ), 'trigger_data' => array(), 'reason' => 'governed canary validation' ),
	);
}

MAD4B_SCP_Provider_Canary_Execution::register_ability();
expect_true( isset( $GLOBALS['mad4b_registered_ability'][ MAD4B_SCP_Provider_Canary_Execution::ABILITY ] ), 'canary wrapper ability must register' );
$registered = $GLOBALS['mad4b_registered_ability'][ MAD4B_SCP_Provider_Canary_Execution::ABILITY ];
expect_same( false, $registered['meta']['annotations']['readonly'], 'canary wrapper is a mutation' );
expect_same( true, $registered['meta']['annotations']['destructive'], 'canary wrapper is destructive/high-risk' );
expect_same( false, $registered['meta']['annotations']['idempotent'], 'canary wrapper is non-idempotent' );
expect_same( 'write', $registered['meta']['mcp']['surface'], 'canary wrapper declares dedicated write authority' );
expect_same( 'high', MAD4B_SCP_Impact_Policy::impact_for( MAD4B_SCP_Provider_Canary_Execution::ABILITY, 'core', array() ), 'canary wrapper must be hard high impact' );
expect_same( true, MAD4B_SCP_Impact_Policy::requires_approval( MAD4B_SCP_Provider_Canary_Execution::ABILITY, 'core', array() ), 'canary wrapper always requires approval' );

$valid = valid_canary_input();
$context = MAD4B_SCP_Provider_Canary_Execution::validate_context( $valid );
expect_true( is_array( $context ), 'exact canary context should validate' );
expect_same( 'bit_pi', $context['provider_id'], 'validated provider is exact' );
expect_same( 'flow.execute', $context['capability_id'], 'validated capability is exact' );

$wrong_candidate = $valid; $wrong_candidate['expected_candidate_sha'] = str_repeat( '9', 40 );
expect_error( MAD4B_SCP_Provider_Canary_Execution::validate_context( $wrong_candidate ), 'mad4b_provider_canary_candidate_mismatch', 'wrong candidate must fail closed' );
$wrong_artifact = $valid; $wrong_artifact['expected_artifact_fingerprint'] = str_repeat( '9', 64 );
expect_error( MAD4B_SCP_Provider_Canary_Execution::validate_context( $wrong_artifact ), 'mad4b_provider_canary_artifact_mismatch', 'wrong artifact must fail closed' );
$wrong_receipt = $valid; $wrong_receipt['expected_behavioral_evidence_digest'] = str_repeat( '9', 64 );
expect_error( MAD4B_SCP_Provider_Canary_Execution::validate_context( $wrong_receipt ), 'mad4b_provider_canary_behavioral_evidence_mismatch', 'wrong behavioral receipt must fail closed' );

$GLOBALS['mad4b_canary_mode'] = 'shadow';
expect_error( MAD4B_SCP_Provider_Canary_Execution::validate_context( $valid ), 'mad4b_provider_canary_stage_invalid', 'shadow capability cannot execute canary' );
$GLOBALS['mad4b_canary_mode'] = 'missing_behavioral';
expect_error( MAD4B_SCP_Provider_Canary_Execution::validate_context( $valid ), 'mad4b_provider_canary_behavioral_evidence_missing', 'missing behavioral evidence cannot execute canary' );
$GLOBALS['mad4b_canary_mode'] = 'canary';

$GLOBALS['mad4b_target_mounted'] = true;
expect_error( MAD4B_SCP_Provider_Canary_Execution::validate_context( $valid ), 'mad4b_provider_canary_target_mounted', 'normal write mount and canary wrapper are mutually exclusive' );
$GLOBALS['mad4b_target_mounted'] = false;
$GLOBALS['mad4b_adapter_opt_in'] = false;
expect_error( MAD4B_SCP_Provider_Canary_Execution::validate_context( $valid ), 'mad4b_provider_canary_adapter_not_opted_in', 'adapter must explicitly opt into exact target' );
$GLOBALS['mad4b_adapter_opt_in'] = true;

$nested = $valid; $nested['target_input'][ MAD4B_SCP_Staging_Write_Authority::APPROVAL_INPUT_KEY ] = '00000000-0000-0000-0000-000000000000';
expect_error( MAD4B_SCP_Provider_Canary_Execution::validate_context( $nested ), 'mad4b_provider_canary_nested_approval_denied', 'nested approval envelope must be rejected' );

$GLOBALS['mad4b_policy_mutate'] = false;
expect_error( MAD4B_SCP_Provider_Canary_Execution::can_execute( $valid ), 'mad4b_provider_canary_mutation_disabled', 'permission must require effective mutation authority' );
$GLOBALS['mad4b_policy_mutate'] = true;
expect_same( true, MAD4B_SCP_Provider_Canary_Execution::can_execute( $valid ), 'valid canary permission path should pass preflight' );

$result = MAD4B_SCP_Provider_Canary_Execution::execute( $valid );
expect_true( is_array( $result ), 'valid canary execution returns evidence' );
expect_same( 1, $GLOBALS['mad4b_adapter_calls'], 'adapter canary executes exactly once' );
expect_same( 'canary', $result['activation_stage_after_execution'], 'successful canary execution never promotes activation stage' );
expect_same( false, $result['promotion_granted'], 'canary execution never grants promotion' );
expect_same( MAD4B_SCP_Provider_Canary_Execution::EVIDENCE_CONTRACT, $result['canary_execution_evidence']['contract'], 'execution emits canonical evidence contract' );
expect_same( false, $result['canary_execution_evidence']['authorizing'], 'execution evidence is non-authorizing' );
expect_same( false, $result['canary_execution_evidence']['activation_granted'], 'execution evidence cannot activate provider capability' );
expect_true( preg_match( '/^[a-f0-9]{64}$/', $result['canary_execution_evidence']['evidence_digest'] ) === 1, 'execution evidence has deterministic digest' );
expect_same( true, $result['execution_audit']['persisted'], 'successful canary side effect must have durable immediate audit evidence' );
expect_same( 'adapter_safe_summary_only', $result['target_result_disclosure'], 'raw provider result disclosure must stay closed' );
expect_true( ! isset( $result['target_result'] ), 'raw provider result must never be returned by the generic wrapper' );
expect_same( array( 'flow_id' => 41, 'queued' => true ), $result['target_result_summary'], 'only adapter-declared safe summary may be returned' );
expect_true( count( $GLOBALS['mad4b_audit'] ) >= 2, 'canary execution emits attempt and success audit evidence' );

$claim = array(
	'approval_required' => true,
	'approval_ticket_id' => '11111111-1111-4111-8111-111111111111',
	'request_id' => 'req-canary-1',
	'agent_public_id' => 'agent-canary-1',
	'grant_id' => 77,
	'server_id' => 'mad4b-write',
	'provider' => 'core',
	'impact' => 'high',
);
$authorized = MAD4B_SCP_Provider_Canary_Execution::persist_authorized_evidence( $claim, $result );
expect_true( is_array( $authorized ), 'authorization-bound canary evidence should persist after approval finalization' );
expect_same( MAD4B_SCP_Provider_Canary_Execution::AUTHORIZED_EVIDENCE_CONTRACT, $authorized['contract'], 'authorized evidence uses canonical contract' );
expect_same( true, $authorized['durable'], 'authorized evidence is durable append-only evidence' );
expect_same( $result['canary_execution_evidence']['evidence_digest'], $authorized['execution_evidence_digest'], 'authorized evidence binds exact execution digest' );
expect_same( $claim['approval_ticket_id'], $authorized['approval_ticket_id'], 'authorized evidence binds exact approval ticket' );
expect_same( false, $authorized['promotion_granted'], 'authorized evidence cannot grant promotion' );

$GLOBALS['mad4b_audit_fail_status'] = 'authorized_evidence';
$persist_failure = MAD4B_SCP_Provider_Canary_Execution::persist_authorized_evidence( $claim, $result );
expect_error( $persist_failure, 'mad4b_provider_canary_authorized_evidence_persist_failed', 'post-side-effect authorization evidence failure must be explicit and terminal' );
$GLOBALS['mad4b_audit_fail_status'] = 'attempt';
$before_calls = $GLOBALS['mad4b_adapter_calls'];
$preflight_failure = MAD4B_SCP_Provider_Canary_Execution::execute( $valid );
expect_error( $preflight_failure, 'mad4b_provider_canary_audit_preflight_failed', 'audit append failure before side effect must fail closed' );
expect_same( $before_calls, $GLOBALS['mad4b_adapter_calls'], 'audit preflight failure must prevent provider side effect' );
$GLOBALS['mad4b_audit_fail_status'] = '';

echo "MAD4B governed provider canary execution contract passed.\n";
