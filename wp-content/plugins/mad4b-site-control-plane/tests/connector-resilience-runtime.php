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
mad4b_assert_true( empty( $guidance['automatic_write_retry_allowed'] ), 'client guidance must deny automatic write retry' );
mad4b_assert_true( empty( $guidance['automatic_enrollment_retry_allowed'] ), 'client guidance must deny automatic enrollment retry' );
mad4b_assert_true( ! empty( $guidance['reconcile_before_retry_when_mutation_state_unknown'] ), 'client guidance must require reconciliation' );
mad4b_assert_true( empty( $guidance['persistent_circuit_breaker_used'] ), 'persistent circuit breaker must remain disabled' );

echo "mad4b.connector-resilience.runtime.v1: PASS\n";
