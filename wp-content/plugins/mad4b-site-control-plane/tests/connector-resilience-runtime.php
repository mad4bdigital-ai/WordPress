<?php

if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		$key = strtolower( (string) $key );
		return preg_replace( '/[^a-z0-9_\-]/', '', $key );
	}
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
}
if ( ! function_exists( 'absint' ) ) {
	function absint( $value ) { return abs( (int) $value ); }
}
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $code;
		private $message;
		private $data;
		public function __construct( $code = '', $message = '', $data = null ) {
			$this->code = $code;
			$this->message = $message;
			$this->data = $data;
		}
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
		public function get_error_data() { return $this->data; }
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $value ) { return $value instanceof WP_Error; }
}
if ( ! class_exists( 'MAD4B_SCP_Audit' ) ) {
	class MAD4B_SCP_Audit {
		public static $records = array();
		public static function record( $ability, $summary, $status = 'ok' ) {
			self::$records[] = array( 'ability' => $ability, 'summary' => $summary, 'status' => $status );
			return true;
		}
	}
}

require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-connector-resilience.php';

function mad4b_assert_true( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

$read_attempts = 0;
$read = MAD4B_SCP_Connector_Resilience::safe_read(
	'timeout_then_success',
	static function () use ( &$read_attempts ) {
		$read_attempts++;
		if ( 1 === $read_attempts ) throw new RuntimeException( 'transport timeout from upstream SECRET-MUST-NOT-LEAK' );
		return array( 'value' => 'ok' );
	}
);
mad4b_assert_true( ! empty( $read['ok'] ), 'transient read must recover' );
mad4b_assert_true( 2 === (int) $read['attempts'], 'transient read must retry exactly once' );
mad4b_assert_true( 2 === $read_attempts, 'read callback must execute twice only' );
mad4b_assert_true( 'ok' === $read['data']['value'], 'read result must survive retry' );

$scalar_read = MAD4B_SCP_Connector_Resilience::safe_read(
	'scalar_read',
	static function () { return 'scalar-ok'; }
);
mad4b_assert_true( ! empty( $scalar_read['ok'] ), 'scalar read must succeed' );
mad4b_assert_true( array_key_exists( 'data', $scalar_read ), 'scalar read data key must be preserved' );
mad4b_assert_true( 'scalar-ok' === $scalar_read['data'], 'scalar read result must not be replaced by type metadata' );

$null_read = MAD4B_SCP_Connector_Resilience::safe_read(
	'null_read',
	static function () { return null; }
);
mad4b_assert_true( ! empty( $null_read['ok'] ), 'null read must succeed' );
mad4b_assert_true( array_key_exists( 'data', $null_read ), 'null read data key must be preserved' );
mad4b_assert_true( null === $null_read['data'], 'null read result must remain null' );

$wp_timeout_attempts = 0;
$wp_timeout = MAD4B_SCP_Connector_Resilience::safe_read(
	'wp_timeout_then_success',
	static function () use ( &$wp_timeout_attempts ) {
		$wp_timeout_attempts++;
		if ( 1 === $wp_timeout_attempts ) return new WP_Error( 'http_request_failed', 'Operation timed out' );
		return array( 'value' => 'wp-ok' );
	}
);
mad4b_assert_true( ! empty( $wp_timeout['ok'] ), 'transient WP_Error read must recover' );
mad4b_assert_true( 2 === $wp_timeout_attempts, 'transient WP_Error must retry exactly once' );
mad4b_assert_true( ! empty( $wp_timeout['automatic_retry_performed'] ), 'recovered transient read must report that its automatic retry was consumed' );
mad4b_assert_true( empty( $wp_timeout['automatic_retry_exhausted'] ), 'successful retry must not report exhausted failure state' );

$exhausted_attempts = 0;
$exhausted = MAD4B_SCP_Connector_Resilience::safe_read(
	'exhausted_timeout',
	static function () use ( &$exhausted_attempts ) {
		$exhausted_attempts++;
		throw new RuntimeException( 'transport timeout remains unavailable' );
	}
);
mad4b_assert_true( empty( $exhausted['ok'] ), 'persistently transient read must remain failed' );
mad4b_assert_true( 2 === $exhausted_attempts, 'persistently transient read must stop after exactly one retry' );
mad4b_assert_true( ! empty( $exhausted['retryable'] ), 'exhausted transient read may remain retryable later' );
mad4b_assert_true( ! empty( $exhausted['automatic_retry_performed'] ), 'exhausted transient read must report the consumed automatic retry' );
mad4b_assert_true( ! empty( $exhausted['automatic_retry_exhausted'] ), 'exhausted transient read must report exhausted retry budget' );
mad4b_assert_true( empty( $exhausted['automatic_retry_allowed'] ), 'exhausted transient read must deny another immediate automatic retry' );
mad4b_assert_true( 'inspect_then_retry_later' === $exhausted['client_action'], 'exhausted transient read action must break immediate retry loops' );

$rate_attempts = 0;
$rate = MAD4B_SCP_Connector_Resilience::safe_read(
	'rate_limit',
	static function () use ( &$rate_attempts ) {
		$rate_attempts++;
		return new WP_Error( 'rate_limit_429', 'Too many requests', array( 'retry_after' => 7 ) );
	}
);
mad4b_assert_true( empty( $rate['ok'] ), 'rate-limited read must remain failed' );
mad4b_assert_true( 1 === $rate_attempts, 'rate limit must not auto retry immediately' );
mad4b_assert_true( ! empty( $rate['retryable'] ), 'rate limit must remain retryable later' );
mad4b_assert_true( empty( $rate['automatic_retry_allowed'] ), 'rate limit must require backoff instead of immediate retry' );
mad4b_assert_true( 'backoff_then_retry' === $rate['client_action'], 'rate-limit client action drifted' );
mad4b_assert_true( 7 === (int) $rate['retry_after_seconds'], 'retry-after hint must be preserved safely' );

$error_code_probe = MAD4B_SCP_Connector_Resilience::safe_read(
	'provider_error_code_sanitization',
	static function () {
		return new WP_Error( 'Provider Secret / Token ABC 123', 'safe-message' );
	}
);
mad4b_assert_true( empty( $error_code_probe['ok'] ), 'provider error code probe must remain an error' );
mad4b_assert_true( 'providersecrettokenabc123' === $error_code_probe['error_code'], 'provider error code must be sanitized before exposure' );
mad4b_assert_true( false === strpos( $error_code_probe['error_code'], ' ' ), 'provider error code must not expose raw formatting' );

$permanent_attempts = 0;
$permanent = MAD4B_SCP_Connector_Resilience::safe_read(
	'permanent_contract_failure',
	static function () use ( &$permanent_attempts ) {
		$permanent_attempts++;
		throw new InvalidArgumentException( 'invalid argument schema SECRET-MUST-NOT-LEAK' );
	}
);
mad4b_assert_true( empty( $permanent['ok'] ), 'permanent failure must fail' );
mad4b_assert_true( 1 === $permanent_attempts, 'permanent failure must not retry' );
mad4b_assert_true( 'contract_or_validation' === $permanent['category'], 'permanent failure classification drifted' );
mad4b_assert_true( empty( $permanent['retryable'] ), 'permanent failure must not be retryable' );
mad4b_assert_true( false === strpos( json_encode( $permanent ), 'SECRET-MUST-NOT-LEAK' ), 'raw exception message leaked from read envelope' );

$mutation_attempts = 0;
$mutation = MAD4B_SCP_Connector_Resilience::execute_mutation(
	'write',
	'mad4b/example-write',
	static function () use ( &$mutation_attempts ) {
		$mutation_attempts++;
		throw new RuntimeException( '503 upstream timeout after possible commit SECRET-MUST-NOT-LEAK' );
	}
);
mad4b_assert_true( is_wp_error( $mutation ), 'mutation exception must return WP_Error' );
mad4b_assert_true( 1 === $mutation_attempts, 'mutation callback must execute exactly once' );
$mutation_data = $mutation->get_error_data();
mad4b_assert_true( is_array( $mutation_data ), 'mutation error data missing' );
mad4b_assert_true( 'unknown' === $mutation_data['mutation_state'], 'uncertain mutation state must be unknown' );
mad4b_assert_true( ! empty( $mutation_data['reconciliation_required'] ), 'mutation exception must require reconciliation' );
mad4b_assert_true( empty( $mutation_data['blind_retry_allowed'] ), 'blind mutation retry must be denied' );
mad4b_assert_true( empty( $mutation_data['automatic_retry_performed'] ), 'mutation must never auto retry' );
mad4b_assert_true( false === strpos( json_encode( $mutation_data ), 'SECRET-MUST-NOT-LEAK' ), 'raw mutation exception message leaked' );

$mutation_wp_attempts = 0;
$mutation_wp = MAD4B_SCP_Connector_Resilience::execute_mutation(
	'write',
	'mad4b/example-remote-write',
	static function () use ( &$mutation_wp_attempts ) {
		$mutation_wp_attempts++;
		return new WP_Error( 'http_request_failed', 'Connection reset after remote request SECRET-MUST-NOT-LEAK' );
	}
);
mad4b_assert_true( is_wp_error( $mutation_wp ), 'transient mutation WP_Error must remain an error' );
mad4b_assert_true( 1 === $mutation_wp_attempts, 'transient mutation WP_Error must not replay callback' );
$mutation_wp_data = $mutation_wp->get_error_data();
mad4b_assert_true( 'unknown' === $mutation_wp_data['mutation_state'], 'transient mutation WP_Error must be uncertain' );
mad4b_assert_true( ! empty( $mutation_wp_data['reconciliation_required'] ), 'transient mutation WP_Error must require reconciliation' );
mad4b_assert_true( empty( $mutation_wp_data['blind_retry_allowed'] ), 'transient mutation WP_Error must deny blind retry' );
mad4b_assert_true( 'reconcile_then_replan' === $mutation_wp_data['client_action'], 'mutation WP_Error client action drifted' );
mad4b_assert_true( false === strpos( json_encode( $mutation_wp_data ), 'SECRET-MUST-NOT-LEAK' ), 'raw mutation WP_Error message leaked' );
$remote_write_audit = null;
foreach ( MAD4B_SCP_Audit::$records as $record ) {
	if ( 'mad4b/mutation-dispatch-error' === $record['ability']
		&& isset( $record['summary']['target'] )
		&& 'mad4b/example-remote-write' === $record['summary']['target'] ) {
		$remote_write_audit = $record;
	}
}
mad4b_assert_true( is_array( $remote_write_audit ), 'mutation target WP_Error must emit sanitized dispatch audit evidence' );
mad4b_assert_true( 'failure' === $remote_write_audit['status'], 'mutation dispatch diagnostic audit status drifted' );
mad4b_assert_true( 'mad4b.mutation-dispatch-error.v1' === $remote_write_audit['summary']['contract'], 'mutation dispatch diagnostic contract drifted' );
mad4b_assert_true( 'http_request_failed' === $remote_write_audit['summary']['original_error_code'], 'sanitized original target error code must be audit-observable' );
mad4b_assert_true( 'unknown' === $remote_write_audit['summary']['mutation_state'], 'diagnostic audit must preserve unknown mutation state' );
mad4b_assert_true( ! empty( $remote_write_audit['summary']['reconciliation_required'] ), 'diagnostic audit must require reconciliation' );
mad4b_assert_true( empty( $remote_write_audit['summary']['blind_retry_allowed'] ), 'diagnostic audit must deny blind retry' );
mad4b_assert_true( empty( $remote_write_audit['summary']['automatic_retry_performed'] ), 'diagnostic audit must prove no mutation retry' );
mad4b_assert_true( empty( $remote_write_audit['summary']['raw_error_message_exposed'] ), 'diagnostic audit must declare raw message redaction' );
mad4b_assert_true( false === strpos( json_encode( $remote_write_audit ), 'SECRET-MUST-NOT-LEAK' ), 'raw mutation message leaked into diagnostic audit' );

$mutation_permanent_attempts = 0;
$mutation_permanent = MAD4B_SCP_Connector_Resilience::execute_mutation(
	'write',
	'mad4b/example-validation-write',
	static function () use ( &$mutation_permanent_attempts ) {
		$mutation_permanent_attempts++;
		return new WP_Error( 'invalid_argument', 'Permanent validation detail SECRET-MUST-NOT-LEAK' );
	}
);
mad4b_assert_true( is_wp_error( $mutation_permanent ), 'permanent mutation WP_Error must remain an error' );
mad4b_assert_true( 1 === $mutation_permanent_attempts, 'permanent mutation WP_Error must execute exactly once' );
$mutation_permanent_data = $mutation_permanent->get_error_data();
mad4b_assert_true( is_array( $mutation_permanent_data ), 'permanent mutation error data missing' );
mad4b_assert_true( 'contract_or_validation' === $mutation_permanent_data['category'], 'permanent mutation category drifted' );
mad4b_assert_true( 'unknown' === $mutation_permanent_data['mutation_state'], 'permanent mutation postcondition must remain unknown after callback entry' );
mad4b_assert_true( ! empty( $mutation_permanent_data['reconciliation_required'] ), 'permanent mutation WP_Error must require reconciliation' );
mad4b_assert_true( empty( $mutation_permanent_data['blind_retry_allowed'] ), 'permanent mutation WP_Error must deny blind retry' );
mad4b_assert_true( 'reconcile_then_replan' === $mutation_permanent_data['client_action'], 'permanent mutation client action drifted' );
mad4b_assert_true( false === strpos( json_encode( $mutation_permanent_data ), 'SECRET-MUST-NOT-LEAK' ), 'raw permanent mutation WP_Error message leaked' );

$fanout_attempts = 0;
$fanout = MAD4B_SCP_Connector_Resilience::run_checks(
	array(
		'healthy' => static function () { return array( 'ready' => true ); },
		'broken' => static function () use ( &$fanout_attempts ) {
			$fanout_attempts++;
			throw new RuntimeException( 'session terminated' );
		},
		'healthy_after_failure' => static function () { return array( 'ready' => true ); },
	),
	array( 'budget_ms' => 5000, 'retry_transient' => true )
);
mad4b_assert_true( ! empty( $fanout['partial'] ), 'fanout must report partial on independent failure' );
mad4b_assert_true( in_array( 'broken', $fanout['failed_checks'], true ), 'failed check missing from partial result' );
mad4b_assert_true( 2 === $fanout_attempts, 'transient failing read must retry once only' );
mad4b_assert_true( ! empty( $fanout['checks']['healthy_after_failure']['ok'] ), 'sibling checks must continue after isolated failure' );

$budget = MAD4B_SCP_Connector_Resilience::run_checks(
	array(
		'slow' => static function () {
			usleep( 1100000 );
			return array( 'ready' => true );
		},
		'skipped_after_budget' => static function () {
			return array( 'ready' => true );
		},
	),
	array( 'budget_ms' => 1000, 'retry_transient' => true )
);
mad4b_assert_true( ! empty( $budget['partial'] ), 'budget exhaustion must produce a partial read result' );
mad4b_assert_true( in_array( 'skipped_after_budget', $budget['skipped_budget_checks'], true ), 'budget-skipped check missing' );
$budget_skip = $budget['checks']['skipped_after_budget'];
mad4b_assert_true( 'skipped_budget' === $budget_skip['state'], 'budget skip state drifted' );
mad4b_assert_true( 'request_budget' === $budget_skip['category'], 'budget skip category drifted' );
mad4b_assert_true( ! empty( $budget_skip['retryable'] ), 'budget skip must remain retryable as a read' );
mad4b_assert_true( empty( $budget_skip['automatic_retry_allowed'] ), 'budget skip must not auto-replay the same request' );
mad4b_assert_true( 'reduce_scope_then_retry_preflight' === $budget_skip['client_action'], 'budget recovery action drifted' );

$guidance = MAD4B_SCP_Connector_Resilience::client_guidance();
mad4b_assert_true( ! empty( $guidance['no_immediate_retry_after_automatic_retry_exhausted'] ), 'client guidance must break immediate retry loops after retry exhaustion' );
mad4b_assert_true( empty( $guidance['automatic_write_retry_allowed'] ), 'client guidance must deny automatic write retry' );
mad4b_assert_true( empty( $guidance['automatic_enrollment_retry_allowed'] ), 'client guidance must deny automatic enrollment retry' );
mad4b_assert_true( ! empty( $guidance['reconcile_before_retry_when_mutation_state_unknown'] ), 'client guidance must require reconciliation' );
mad4b_assert_true( empty( $guidance['persistent_circuit_breaker_used'] ), 'persistent circuit breaker must remain disabled' );
mad4b_assert_true( 1 === (int) $guidance['preferred_parallelism'], 'preferred read parallelism must remain sequential' );
mad4b_assert_true( 2 === (int) $guidance['read_parallelism_max'], 'read parallelism hard ceiling drifted' );
mad4b_assert_true( 1 === (int) $guidance['reconnect_attempts'], 'client reconnect budget drifted' );
mad4b_assert_true( ! empty( $guidance['replay_read_after_reconnect'] ), 'read replay after reconnect must remain allowed' );
mad4b_assert_true( empty( $guidance['replay_mutation_after_reconnect'] ), 'mutation replay after reconnect must remain forbidden' );
mad4b_assert_true( ! empty( $guidance['snapshot_identity_required'] ), 'snapshot identity must remain required' );
mad4b_assert_true( ! empty( $guidance['discard_partial_on_generation_change'] ), 'generation drift must discard partial reads' );
mad4b_assert_true( ! empty( $guidance['resume_completed_reads_on_generation_match'] ), 'same-generation reconnect must permit partial resume' );
mad4b_assert_true( empty( $guidance['persistent_session_breaker_used'] ), 'session breaker must remain request/client local' );

echo "mad4b.connector-resilience.runtime.v1: PASS\n";
