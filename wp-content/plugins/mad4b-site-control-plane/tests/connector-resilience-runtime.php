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

$dispatch_preflight_attempts = 0;
$dispatch_preflight = MAD4B_SCP_Connector_Resilience::execute_mutation(
	'write',
	'mad4b/plugin-package-apply',
	static function () use ( &$dispatch_preflight_attempts ) {
		$dispatch_preflight_attempts++;
		return new WP_Error( 'mad4b_write_dispatch_schema_drift', 'Target schema changed before callback entry SECRET-MUST-NOT-LEAK' );
	}
);
mad4b_assert_true( is_wp_error( $dispatch_preflight ), 'pre-target dispatch failure must remain an error' );
mad4b_assert_true( 1 === $dispatch_preflight_attempts, 'pre-target dispatch probe must execute exactly once' );
$dispatch_preflight_data = $dispatch_preflight->get_error_data();
mad4b_assert_true( 'not_started' === $dispatch_preflight_data['mutation_state'], 'pre-target dispatch failure must prove mutation not started' );
mad4b_assert_true( empty( $dispatch_preflight_data['reconciliation_required'] ), 'pre-target dispatch failure must not require postcondition reconciliation' );
mad4b_assert_true( 'repair_dispatch_then_replan' === $dispatch_preflight_data['client_action'], 'pre-target dispatch recovery action drifted' );
mad4b_assert_true( empty( $dispatch_preflight_data['blind_retry_allowed'] ), 'pre-target dispatch failure must still require a fresh plan before retry' );
mad4b_assert_true( false === strpos( json_encode( $dispatch_preflight_data ), 'SECRET-MUST-NOT-LEAK' ), 'raw pre-target dispatch error leaked' );

$recovered_session_attempts = 0;
$recovered_session = MAD4B_SCP_Connector_Resilience::run_checks(
	array(
		'metadata' => static function () use ( &$recovered_session_attempts ) {
			$recovered_session_attempts++;
			if ( 1 === $recovered_session_attempts ) throw new RuntimeException( 'session terminated' );
			return array( 'ready' => true );
		},
		'sibling' => static function () { return array( 'ready' => true ); },
	),
	array( 'budget_ms' => 5000, 'retry_transient' => true )
);
mad4b_assert_true( empty( $recovered_session['partial'] ), 'one recovered session termination must not poison the read transaction' );
mad4b_assert_true( 1 === (int) $recovered_session['session_termination_count'], 'recovered session termination must be counted exactly once' );
mad4b_assert_true( empty( $recovered_session['session_breaker_open'] ), 'one recovered termination must remain below breaker budget' );
mad4b_assert_true( ! empty( $recovered_session['checks']['sibling']['ok'] ), 'sibling read must continue after one recovered termination' );

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
mad4b_assert_true( ! empty( $fanout['partial'] ), 'fanout must report partial after session breaker opens' );
mad4b_assert_true( in_array( 'broken', $fanout['failed_checks'], true ), 'failed session check missing from partial result' );
mad4b_assert_true( 2 === $fanout_attempts, 'session-terminated read must consume one bounded automatic retry only' );
mad4b_assert_true( 2 === (int) $fanout['session_termination_count'], 'session termination budget must count both failed attempts' );
mad4b_assert_true( ! empty( $fanout['session_breaker_open'] ), 'repeated session termination must open the request-local breaker' );
mad4b_assert_true( in_array( 'healthy_after_failure', $fanout['skipped_session_breaker_checks'], true ), 'fanout must stop launching sibling reads after session termination budget is exhausted' );
mad4b_assert_true( 'skipped_session_breaker' === $fanout['checks']['healthy_after_failure']['state'], 'post-breaker sibling must be explicitly skipped' );
mad4b_assert_true( 'reconnect_snapshot_then_resume' === $fanout['checks']['healthy_after_failure']['client_action'], 'session breaker must direct the client to reconnect and generation-check before resume' );

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
mad4b_assert_true( 'request_local' === $guidance['session_breaker_scope'], 'session breaker scope must remain request-local' );
mad4b_assert_true( 'session_terminated' === $guidance['session_termination_category'], 'session termination must have a dedicated error category' );
mad4b_assert_true( ! empty( $guidance['metadata_micro_read_preferred'] ), 'metadata micro-read must be preferred over multi-call metadata fanout' );
mad4b_assert_true( 'mad4b/read-metadata-envelope' === $guidance['metadata_envelope_ability'], 'metadata envelope ability drifted' );
mad4b_assert_true( ! empty( $guidance['resume_after_reconnect_requires_generation_match'] ), 'resume after reconnect must require runtime generation match' );

echo "mad4b.connector-resilience.runtime.v1: PASS\n";
