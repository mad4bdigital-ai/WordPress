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
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
}
require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-read-consistency.php';

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

$rate_storage_attempts = 0;
$rate_storage = MAD4B_SCP_Connector_Resilience::safe_read(
	'rate_storage',
	static function () use ( &$rate_storage_attempts ) {
		$rate_storage_attempts++;
		return new WP_Error(
			'mad4b_abuse_rate_storage_unavailable',
			'Rate-limit writer topology is unavailable.',
			array(
				'cause_code' => 'mad4b_database_topology_not_write_safe',
				'topology_blockers' => array( 'uncertified_database_router_dropin', 'database_topology_probe_error' ),
				'blind_retry_allowed' => false,
				'authorizing' => false,
				'recovery_read_ability' => 'mad4b/session-safe-diagnostics',
				'bounded_repair_ability' => 'mad4b/query-monitor-db-attribution-bootstrap',
				'recheck_action' => 'retry_original_operation_after_topology_repair',
				'surface' => 'discovery',
				'secret_untrusted_detail' => 'SECRET-MUST-NOT-LEAK',
			)
		);
	}
);
mad4b_assert_true( empty( $rate_storage['ok'] ), 'rate storage failure must remain failed' );
mad4b_assert_true( 1 === $rate_storage_attempts, 'rate storage failure must not auto retry' );
mad4b_assert_true( 'rate_storage' === $rate_storage['category'], 'rate storage category drifted' );
mad4b_assert_true( ! empty( $rate_storage['retryable'] ) && empty( $rate_storage['automatic_retry_allowed'] ), 'rate storage must be repairable later without immediate replay' );
mad4b_assert_true( 'repair_rate_storage_then_retry' === $rate_storage['client_action'], 'rate storage client action drifted' );
mad4b_assert_true( isset( $rate_storage['recovery'] ) && is_array( $rate_storage['recovery'] ), 'rate storage recovery metadata missing' );
mad4b_assert_true( 'mad4b_database_topology_not_write_safe' === $rate_storage['recovery']['cause_code'], 'rate storage cause code lost' );
mad4b_assert_true( in_array( 'uncertified_database_router_dropin', $rate_storage['recovery']['topology_blockers'], true ), 'rate storage topology blocker lost' );
mad4b_assert_true( 'mad4b/session-safe-diagnostics' === $rate_storage['recovery']['recovery_read_ability'], 'rate storage read recovery ability lost' );
mad4b_assert_true( 'mad4b/query-monitor-db-attribution-bootstrap' === $rate_storage['recovery']['bounded_repair_ability'], 'rate storage bounded repair ability lost' );
mad4b_assert_true( empty( $rate_storage['recovery']['blind_retry_allowed'] ), 'rate storage recovery must forbid blind retry' );
mad4b_assert_true( ! array_key_exists( 'secret_untrusted_detail', $rate_storage['recovery'] ), 'untrusted rate storage error data leaked through recovery whitelist' );

$rate_storage_dispatch = MAD4B_SCP_Connector_Resilience::execute_read(
	'mad4b/example-rate-storage-read',
	static function () {
		return new WP_Error(
			'mad4b_abuse_rate_storage_unavailable',
			'Rate-limit writer topology is unavailable.',
			array(
				'cause_code' => 'mad4b_database_topology_not_write_safe',
				'topology_blockers' => array( 'uncertified_database_router_dropin' ),
				'blind_retry_allowed' => false,
				'authorizing' => false,
				'recovery_read_ability' => 'mad4b/session-safe-diagnostics',
				'bounded_repair_ability' => 'mad4b/query-monitor-db-attribution-bootstrap',
				'recheck_action' => 'retry_original_operation_after_topology_repair',
			)
		);
	}
);
mad4b_assert_true( is_wp_error( $rate_storage_dispatch ), 'governed read dispatcher must preserve rate storage failure' );
$rate_storage_dispatch_data = $rate_storage_dispatch->get_error_data();
mad4b_assert_true( 'rate_storage' === $rate_storage_dispatch_data['category'], 'governed read dispatcher lost rate storage category' );
mad4b_assert_true( 'repair_rate_storage_then_retry' === $rate_storage_dispatch_data['client_action'], 'governed read dispatcher lost rate storage recovery action' );
mad4b_assert_true( 'mad4b/query-monitor-db-attribution-bootstrap' === $rate_storage_dispatch_data['recovery']['bounded_repair_ability'], 'governed read dispatcher lost bounded rate storage repair metadata' );
mad4b_assert_true( empty( $rate_storage_dispatch_data['blind_retry_allowed'] ), 'governed read dispatcher must deny blind retry for rate storage failure' );

$wrapped_rate_storage_attempts = 0;
$wrapped_rate_storage = MAD4B_SCP_Connector_Resilience::safe_read(
	'wrapped_rate_storage',
	static function () use ( &$wrapped_rate_storage_attempts ) {
		$wrapped_rate_storage_attempts++;
		throw new RuntimeException( 'RuntimeException: Error calling MCP tool: Rate-limit writer topology is unavailable.' );
	}
);
mad4b_assert_true( empty( $wrapped_rate_storage['ok'] ), 'wrapped rate storage exception must remain failed' );
mad4b_assert_true( 1 === $wrapped_rate_storage_attempts, 'wrapped rate storage exception must not auto retry' );
mad4b_assert_true( 'rate_storage' === $wrapped_rate_storage['category'], 'wrapped rate storage exception category drifted' );
mad4b_assert_true( 'repair_rate_storage_then_retry' === $wrapped_rate_storage['client_action'], 'wrapped rate storage exception recovery action drifted' );
mad4b_assert_true( empty( $wrapped_rate_storage['automatic_retry_allowed'] ), 'wrapped rate storage exception must not blind retry' );

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

$nested_not_started_attempts = 0;
$nested_not_started = MAD4B_SCP_Connector_Resilience::execute_mutation(
	'write',
	'mad4b/plugin-package-apply',
	static function () use ( &$nested_not_started_attempts ) {
		$nested_not_started_attempts++;
		return new WP_Error( 'mad4b_write_dispatch_target_not_started', 'Nested dispatcher proved target callback was never entered SECRET-MUST-NOT-LEAK' );
	}
);
mad4b_assert_true( is_wp_error( $nested_not_started ), 'nested not-started wrapper must remain an error' );
mad4b_assert_true( 1 === $nested_not_started_attempts, 'nested not-started wrapper must execute exactly once' );
$nested_not_started_data = $nested_not_started->get_error_data();
mad4b_assert_true( 'not_started' === $nested_not_started_data['mutation_state'], 'outer resilience wrapper must preserve proven not-started state' );
mad4b_assert_true( empty( $nested_not_started_data['reconciliation_required'] ), 'proven nested pre-target failure must not require postcondition reconciliation' );
mad4b_assert_true( 'mad4b_write_dispatch_target_not_started' === $nested_not_started_data['original_error_code'], 'outer resilience wrapper lost the inner not-started reason code' );
mad4b_assert_true( 'repair_dispatch_then_replan' === $nested_not_started_data['client_action'], 'nested pre-target recovery action drifted' );
mad4b_assert_true( false === strpos( json_encode( $nested_not_started_data ), 'SECRET-MUST-NOT-LEAK' ), 'nested pre-target raw error leaked' );

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

$read_consistency_reflection = new ReflectionClass( 'MAD4B_SCP_Read_Consistency' );

$next_step_method = $read_consistency_reflection->getMethod( 'session_safe_next_step' );
$next_step_method->setAccessible( true );
$write_next = $next_step_method->invoke( null, false, array( 'write_authority_not_effective' ) );
mad4b_assert_true( 'request_full_staging_authority_handshake' === $write_next['action'], 'session-safe write recovery route drifted' );
mad4b_assert_true( 'mad4b/full-staging-authority-handshake' === $write_next['ability'], 'session-safe write recovery ability drifted' );
mad4b_assert_true( ! empty( $write_next['read_only'] ) && empty( $write_next['automatic_apply_allowed'] ), 'session-safe write recovery must remain read-only and non-automatic' );

$skills_next = $next_step_method->invoke( null, false, array( 'skills_runtime_not_effective' ) );
mad4b_assert_true( 'mad4b/reconcile-managed-skills' === $skills_next['ability'], 'session-safe skills recovery ability drifted' );
mad4b_assert_true( ! empty( $skills_next['explicit_authority_required'] ) && empty( $skills_next['automatic_apply_allowed'] ), 'skills recovery must require explicit authority and never auto-apply' );

$partial_next = $next_step_method->invoke( null, true, array() );
mad4b_assert_true( 'inspect_partial_report_then_retry_missing_scope' === $partial_next['action'], 'partial session-safe recovery route drifted' );
mad4b_assert_true( '' === $partial_next['ability'] && ! empty( $partial_next['read_only'] ), 'partial session-safe recovery must not invent a mutation target' );

$performance_method = $read_consistency_reflection->getMethod( 'performance_observation' );
$performance_method->setAccessible( true );
$performance = $performance_method->invoke( null, array(
	'request_elapsed_ms' => 1087,
	'db_query_count' => 425,
	'included_file_count' => 6040,
	'memory_usage_bytes' => 48234496,
	'peak_memory_bytes' => 48234496,
), 20000, str_repeat( 'd', 64 ), 13 );
mad4b_assert_true( 'mad4b.session-safe-performance-observation.v1' === $performance['contract'], 'session-safe performance observation contract drifted' );
mad4b_assert_true( 'observed_within_diagnostic_budget' === $performance['classification'], 'session-safe request budget classification drifted' );
mad4b_assert_true( 19987 === (int) $performance['diagnostic_budget_headroom_ms'], 'session-safe request budget headroom drifted' );
mad4b_assert_true( 425 === (int) $performance['db_query_count'], 'session-safe comparative DB query signal lost' );
mad4b_assert_true( 1074 === (int) $performance['request_overhead_ms'], 'session-safe request overhead decomposition drifted' );
mad4b_assert_true( 6040 === (int) $performance['included_file_count'], 'session-safe comparative include-count signal lost' );
mad4b_assert_true( ! empty( $performance['comparison_required'] ), 'session-safe performance must require exact-release comparison' );
mad4b_assert_true( 'previous_exact_staging_release' === $performance['comparison_baseline_scope'], 'session-safe performance baseline scope drifted' );
mad4b_assert_true( empty( $performance['fixed_universal_db_query_threshold_applied'] ), 'session-safe performance must not invent a universal DB-query threshold' );
mad4b_assert_true( empty( $performance['authorizing'] ) && ! empty( $performance['read_only'] ) && empty( $performance['mutation_performed'] ), 'session-safe performance observation widened authority or mutation' );

$over_budget = $performance_method->invoke( null, array(
	'request_elapsed_ms' => 2500,
	'db_query_count' => 1,
	'included_file_count' => 1,
	'memory_usage_bytes' => 1,
	'peak_memory_bytes' => 1,
), 1000, str_repeat( 'e', 64 ), 1200 );
mad4b_assert_true( 'diagnostic_budget_exceeded' === $over_budget['classification'], 'session-safe performance must classify explicit request budget exhaustion' );
mad4b_assert_true( 0 === (int) $over_budget['diagnostic_budget_headroom_ms'], 'over-budget session-safe request must expose zero headroom' );

$compact_method = $read_consistency_reflection->getMethod( 'compact_status_data' );
$compact_method->setAccessible( true );
$oversized_blockers = array();
for ( $i = 0; $i < 100; $i++ ) $oversized_blockers[] = 'blocker-' . $i;
$compact = $compact_method->invoke( null, 'staging_certification', array(
	'contract' => 'test.contract',
	'ready' => false,
	'state' => 'blocked',
	'blocking_gates' => $oversized_blockers,
	'providers' => array_fill( 0, 100, str_repeat( 'provider-noise', 100 ) ),
	'unknown_large_field' => str_repeat( 'X', 50000 ),
	'build' => array(
		'version' => '0.4.0-rc.77',
		'source_commit_sha' => str_repeat( 'a', 40 ),
		'build_fingerprint' => str_repeat( 'b', 64 ),
	),
) );
mad4b_assert_true( 12 === count( $compact['blocking_gates'] ), 'session-safe compact projection must cap blocker lists' );
mad4b_assert_true( ! array_key_exists( 'unknown_large_field', $compact ), 'session-safe compact projection must drop unknown large fields' );
mad4b_assert_true( ! array_key_exists( 'providers', $compact ), 'session-safe compact projection must not leak unbounded provider maps' );
mad4b_assert_true( '0.4.0-rc.77' === $compact['build']['version'], 'session-safe compact projection must preserve bounded build identity' );

$operator_method = $read_consistency_reflection->getMethod( 'session_safe_operator_summary' );
$operator_method->setAccessible( true );
$operator_sections = array(
	'runtime' => array(
		'checks' => array(
			'adapter_lifecycle' => array( 'summary' => array(
				'ready' => false,
				'state' => 'adapter_ability_lifecycle_incomplete',
				'expected_count' => 14,
				'registered_count' => 10,
				'missing_abilities' => array( 'media/search', 'fluentforms/status', 'litespeed/status', 'mad4b/translation-status' ),
			) ),
			'write_authority' => array( 'summary' => array(
				'effective_authority_ready' => false,
				'candidate_binding_match' => false,
				'current_grant_snapshot_ready' => false,
				'current_readiness_blockers' => array( 'exact_write_grants_missing' ),
			) ),
			'database_topology' => array( 'summary' => array(
				'ready' => false,
				'read_your_writes' => false,
				'blockers' => array( 'uncertified_database_router_dropin' ),
			) ),
			'mcp_protocol_profile' => array( 'summary' => array(
				'ready' => false,
				'blocker' => 'adapter_version_uncertified',
				'certified_adapter_version' => '0.7.0',
				'runtime_adapter_version' => '0.8.0',
				'adapter_version_match' => false,
			) ),
			'skills_runtime' => array( 'summary' => array( 'effective_skill_ready' => true ) ),
		),
	),
);
$operator_summary = $operator_method->invoke( null, $operator_sections, false, array( 'write_authority_not_effective' ), array( 'environment' => 'staging' ) );
mad4b_assert_true( 'BLOCKED' === $operator_summary['state'], 'session-safe operator summary must classify current authority/topology drift as BLOCKED' );
mad4b_assert_true( 'staging' === $operator_summary['effective_environment'], 'session-safe operator summary must preserve effective Site Profile environment' );
mad4b_assert_true( in_array( 'reconcile_exact_staging_write_authority', $operator_summary['next_actions'], true ), 'session-safe operator summary must route authority drift to exact reconciliation' );
mad4b_assert_true( in_array( 'repair_query_monitor_db_attribution_then_retry', $operator_summary['next_actions'], true ), 'session-safe operator summary must route topology drift to bounded Query Monitor repair' );
mad4b_assert_true( false === $operator_summary['signals']['adapter_lifecycle_ready'], 'session-safe operator summary must expose adapter lifecycle readiness' );
mad4b_assert_true( in_array( 'adapter_ability_lifecycle_incomplete', $operator_summary['reasons'], true ), 'session-safe operator summary must retain adapter lifecycle blocker' );
mad4b_assert_true( in_array( 'repair_adapter_ability_lifecycle_registration', $operator_summary['next_actions'], true ), 'session-safe operator summary must route adapter lifecycle repair' );
mad4b_assert_true( false === $operator_summary['signals']['database_topology_ready'], 'session-safe operator summary must expose topology readiness' );
mad4b_assert_true( false === $operator_summary['signals']['mcp_protocol_profile_ready'], 'session-safe operator summary must expose MCP protocol readiness' );
mad4b_assert_true( in_array( 'adapter_version_uncertified', $operator_summary['reasons'], true ), 'session-safe operator summary must retain exact protocol blocker' );
mad4b_assert_true( in_array( 'deploy_exact_certified_runtime_release', $operator_summary['next_actions'], true ), 'session-safe operator summary must route protocol drift to exact certified runtime deployment' );
mad4b_assert_true( empty( $operator_summary['authorizing'] ) && empty( $operator_summary['mutation_performed'] ), 'session-safe operator summary must remain non-authorizing/read-only' );

$bound_method = $read_consistency_reflection->getMethod( 'bound_session_safe_report' );
$bound_method->setAccessible( true );
$synthetic_sections = array();
foreach ( array( 'identity', 'runtime', 'certification', 'providers' ) as $bundle ) {
	$checks = array();
	for ( $i = 0; $i < 40; $i++ ) {
		$checks[ 'check_' . $i ] = array(
			'ok' => true,
			'state' => 'ready',
			'category' => 'none',
			'elapsed_ms' => 1,
			'summary' => array( 'blockers' => $oversized_blockers, 'noise' => str_repeat( 'N', 300 ) ),
			'evidence_digest' => hash( 'sha256', $bundle . ':' . $i ),
		);
	}
	$synthetic_sections[ $bundle ] = array(
		'state' => 'ready',
		'partial' => false,
		'failed_checks' => array(),
		'retryable_checks' => array(),
		'check_count' => count( $checks ),
		'checks' => $checks,
		'evidence_digest' => hash( 'sha256', $bundle ),
	);
}
$bounded = $bound_method->invoke( null, array(
	'contract' => 'mad4b.session-safe-diagnostics.v1',
	'state' => 'ready',
	'partial' => false,
	'read_transaction_id' => 'rtx_runtime_test_1234',
	'runtime_generation' => str_repeat( 'c', 64 ),
	'operator_summary' => $operator_summary,
	'sections' => $synthetic_sections,
	'read_only' => true,
	'mutation_performed' => false,
	'production_mutation_performed' => false,
) );
mad4b_assert_true( ! empty( $bounded['payload_reduced'] ), 'oversized session-safe report must reduce itself' );
mad4b_assert_true( isset( $bounded['operator_summary'] ) && 'BLOCKED' === $bounded['operator_summary']['state'], 'bounded session-safe report must preserve operator summary' );
mad4b_assert_true( $bounded['response_bytes'] <= MAD4B_SCP_Read_Consistency::MAX_SESSION_SAFE_REPORT_BYTES, 'session-safe report must enforce the hard response byte cap' );
$bounded_json = wp_json_encode( $bounded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
mad4b_assert_true( false !== $bounded_json && strlen( $bounded_json ) <= MAD4B_SCP_Read_Consistency::MAX_SESSION_SAFE_REPORT_BYTES, 'final encoded session-safe report must remain under the hard byte cap' );
mad4b_assert_true( empty( $bounded['mutation_performed'] ), 'session-safe diagnostics must remain read-only' );
mad4b_assert_true( empty( $bounded['production_mutation_performed'] ), 'session-safe diagnostics must never mutate Production' );

echo "mad4b.connector-resilience.runtime.v1: PASS\n";
