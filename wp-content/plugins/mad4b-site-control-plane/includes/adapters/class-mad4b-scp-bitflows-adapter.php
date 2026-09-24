<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class MAD4B_SCP_BitFlows_Adapter extends MAD4B_SCP_Adapter_Base {
	const CORRELATION_CONTRACT = 'mad4b.bitflows-correlation.v1';
	const READBACK_CONTRACT = 'mad4b.bitflows-execution-readback.v1';
	const CORRELATION_KEY = '__mad4b_execution';
	private static $durable_hooks_booted = false;

	public static function boot() {
		if ( self::$durable_hooks_booted || ! function_exists( 'add_filter' ) ) return;
		self::$durable_hooks_booted = true;
		add_filter( 'mad4b_scp_durable_reconciliation_verified', array( __CLASS__, 'verify_durable_reconciliation' ), 20, 3 );
	}
	public function id() { return 'bitflows'; }
	public function label() { return 'Bit Flows'; }
	public function is_available() { return class_exists( 'BitApps\\Pi\\Model\\Flow' ); }
	public function ability_names() { return array( 'read' => array( 'bitflows/status', 'bitflows/list-flows', 'bitflows/get-flow', 'bitflows/get-executions' ), 'content' => array(), 'admin' => array( 'bitflows/run-flow' ) ); }
	protected function certified_provider_key() { return 'bit_pi'; }
	protected function detect_plugin_version() { if ( defined( 'BITPI_VERSION' ) ) return BITPI_VERSION; if ( defined( 'BIT_PI_VERSION' ) ) return BIT_PI_VERSION; return ''; }
	public function register_abilities() {
		$this->add_ability( 'bitflows/status', 'Get Bit Flows Status', 'status', array( 'MAD4B_SCP_Policy', 'can_read' ) );
		$this->add_ability( 'bitflows/list-flows', 'List Bit Flows', 'list_flows', array( 'MAD4B_SCP_Policy', 'can_read' ), $this->schema( array(
			'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 50 ),
		) ) );
		$flow_schema = $this->schema(
			array( 'flow_id' => array( 'type' => 'integer', 'minimum' => 1 ) ),
			array( 'flow_id' )
		);
		$this->add_ability( 'bitflows/get-flow', 'Get Bit Flow', 'get_flow', array( 'MAD4B_SCP_Policy', 'can_read' ), $flow_schema );
		$this->add_ability( 'bitflows/get-executions', 'Get Bit Flow Executions', 'get_executions', array( 'MAD4B_SCP_Policy', 'can_read' ), $this->schema(
			array(
				'flow_id' => array( 'type' => 'integer', 'minimum' => 1 ),
				'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20 ),
			),
			array( 'flow_id' )
		) );
		$this->add_ability( 'bitflows/run-flow', 'Run Bit Flow', 'run_flow', array( $this, 'can_run_flow' ), $this->schema(
			array(
				'flow_id' => array( 'type' => 'integer', 'minimum' => 1 ),
				'expected_flow_sha256' => array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64 ),
				'expected_plan_sha256' => array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64 ),
				'idempotency_key' => array(
					'type' => 'string',
					'minLength' => 8,
					'maxLength' => 191,
					'pattern' => '^[A-Za-z0-9._:-]+$',
				),
				'trigger_data' => array( 'type' => 'object', 'default' => array() ),
				'reason' => array( 'type' => 'string', 'minLength' => 3, 'maxLength' => 500 ),
			),
			array( 'flow_id', 'expected_flow_sha256', 'expected_plan_sha256', 'idempotency_key', 'reason' )
		), 'admin', false, true, false );
	}
	public function status() {
		$status = parent::status();
		$status['contracts'] = array(
			'flow_model' => class_exists( 'BitApps\\Pi\\Model\\Flow' ),
			'flow_node' => class_exists( 'BitApps\\Pi\\Model\\FlowNode' ),
			'flow_executor' => class_exists( 'BitApps\\Pi\\src\\Flow\\FlowExecutor' ),
			'flow_history' => class_exists( 'BitApps\\Pi\\Model\\FlowHistory' ),
			'flow_log' => class_exists( 'BitApps\\Pi\\Model\\FlowLog' ),
			'durable_execution' => class_exists( 'MAD4B_SCP_Durable_Execution' ),
		);
		$status['runtime_contract_diagnostic'] = array(
			'contract' => 'mad4b.bitflows-runtime-contract-diagnostic.v1',
			'provider_runtime_available' => $this->is_available(),
			'provider_version' => '' !== $this->detect_plugin_version() ? $this->detect_plugin_version() : ( isset( $status['version'] ) ? (string) $status['version'] : '' ),
			'exact_expected_symbols' => $status['contracts'],
			'declared_class_suffix_candidates' => array(
				'flow_model' => self::declared_class_suffix_matches( array( '\\Model\\Flow' ) ),
				'flow_node' => self::declared_class_suffix_matches( array( '\\Model\\FlowNode' ) ),
				'flow_executor' => self::declared_class_suffix_matches( array( '\\Flow\\FlowExecutor', '\\FlowExecutor' ) ),
				'flow_history' => self::declared_class_suffix_matches( array( '\\Model\\FlowHistory' ) ),
			),
			'autoload_or_bootstrap_mutation_attempted' => false,
			'filesystem_scan_performed' => false,
			'authorizing' => false,
			'read_only' => true,
		);
		$status['execution_enabled'] = defined( 'MAD4B_MCP_BITFLOWS_EXECUTION_ENABLED' ) && true === MAD4B_MCP_BITFLOWS_EXECUTION_ENABLED;
		$status['flow_policy_default'] = 'deny';
		$status['native_mcp_role'] = 'client';
		return $status;
	}

	private static function declared_class_suffix_matches( array $suffixes ) {
		$matches = array();
		foreach ( get_declared_classes() as $class ) {
			foreach ( $suffixes as $suffix ) {
				$suffix = (string) $suffix;
				if ( '' === $suffix || strlen( $class ) < strlen( $suffix ) ) continue;
				if ( substr( $class, -strlen( $suffix ) ) === $suffix ) {
					$matches[] = $class;
					break;
				}
			}
			if ( count( $matches ) >= 20 ) break;
		}
		return array_values( array_unique( $matches ) );
	}
	public function can_run_flow( $input = null ) {
		if ( ! current_user_can( 'manage_options' ) ) return false;
		if ( ! defined( 'MAD4B_MCP_BITFLOWS_EXECUTION_ENABLED' ) || true !== MAD4B_MCP_BITFLOWS_EXECUTION_ENABLED ) return false;
		return (bool) apply_filters( 'mad4b_scp_bitflows_run_permission', true, $input, get_current_user_id() );
	}
	public function supports_canary_execution( $ability_name ) {
		return 'bitflows/run-flow' === (string) $ability_name;
	}
	public function execute_canary( $ability_name, array $input ) {
		if ( ! $this->supports_canary_execution( $ability_name ) ) return parent::execute_canary( $ability_name, $input );
		// Direct adapter invocation intentionally re-runs the native permission gate;
		// the wrapper approval never substitutes for Bit Flows' explicit execution
		// enablement or its provider-local policy controls.
		if ( true !== $this->can_run_flow( $input ) ) return new WP_Error( 'mad4b_bitflows_canary_permission_denied', 'Bit Flows canary execution is disabled by the provider runtime permission policy.' );
		return $this->run_flow( $input );
	}
	public function canary_result_summary( $ability_name, $result ) {
		if ( 'bitflows/run-flow' !== (string) $ability_name || ! is_array( $result ) ) return parent::canary_result_summary( $ability_name, $result );
		return array(
			'provider_id' => isset( $result['provider_id'] ) ? (string) $result['provider_id'] : 'bit_pi',
			'flow_id' => isset( $result['flow_id'] ) ? (int) $result['flow_id'] : 0,
			'provider_execution_ref' => isset( $result['provider_execution_ref'] ) ? (string) $result['provider_execution_ref'] : '',
			'provider_status' => isset( $result['provider_status'] ) ? (string) $result['provider_status'] : '',
			'idempotency_replayed' => ! empty( $result['idempotency_replayed'] ),
			'readback_verified' => ! empty( $result['readback_verified'] ),
		);
	}
	public function list_flows( $input ) {
		if ( ! $this->is_available() ) return $this->unavailable_error();
		$limit = isset( $input['limit'] ) ? max( 1, min( 100, absint( $input['limit'] ) ) ) : 50;
		$class = 'BitApps\\Pi\\Model\\Flow';
		try {
			$flows = $class::select( array( 'id', 'title', 'run_count', 'is_active', 'trigger_type', 'listener_type' ) )->desc()->take( $limit )->get();
			return array( 'flows' => $this->normalize( $flows ), 'count' => is_countable( $flows ) ? count( $flows ) : 0, 'execution_enabled' => $this->can_run_flow() );
		} catch ( Throwable $e ) { return new WP_Error( 'mad4b_bitflows_list_failed', $e->getMessage() ); }
	}
	public function get_flow( $input ) {
		if ( ! $this->is_available() ) return $this->unavailable_error();
		$class = 'BitApps\\Pi\\Model\\Flow';
		$id = absint( $input['flow_id'] );
		try {
			$flow = $class::select( array( 'id', 'title', 'run_count', 'is_active', 'trigger_type', 'listener_type', 'is_hook_capture', 'settings', 'map' ) )->findOne( array( 'id' => $id ) );
			if ( ! $flow ) return new WP_Error( 'mad4b_bitflows_flow_missing', 'Flow not found.' );
			$fingerprint = $this->flow_fingerprint( $id, $flow );
			if ( is_wp_error( $fingerprint ) ) return $fingerprint;
			return array( 'flow' => $this->redact( $this->normalize( $flow ) ), 'flow_sha256' => $fingerprint );
		} catch ( Throwable $e ) { return new WP_Error( 'mad4b_bitflows_get_failed', $e->getMessage() ); }
	}
	public function get_executions( $input ) {
		if ( ! class_exists( 'BitApps\\Pi\\Model\\FlowHistory' ) ) return new WP_Error( 'mad4b_bitflows_history_unavailable', 'Bit Flows history contract is unavailable.' );
		$class = 'BitApps\\Pi\\Model\\FlowHistory';
		$limit = isset( $input['limit'] ) ? max( 1, min( 100, absint( $input['limit'] ) ) ) : 20;
		try {
			$items = $class::where( 'flow_id', absint( $input['flow_id'] ) )->desc()->take( $limit )->select( array( 'id', 'flow_id', 'parent_history_id', 'status', 'created_at', 'updated_at' ) )->get();
			return array( 'flow_id' => absint( $input['flow_id'] ), 'executions' => $this->normalize( $items ), 'count' => is_countable( $items ) ? count( $items ) : 0 );
		} catch ( Throwable $e ) { return new WP_Error( 'mad4b_bitflows_history_failed', $e->getMessage() ); }
	}
	public function run_flow( $input ) {
		if ( ! $this->is_available() || ! class_exists( 'BitApps\\Pi\\src\\Flow\\FlowExecutor' ) ) return $this->unavailable_error();
		if ( ! class_exists( 'BitApps\\Pi\\Model\\FlowLog' ) || ! class_exists( 'BitApps\\Pi\\Model\\FlowHistory' ) ) return new WP_Error( 'mad4b_bitflows_readback_unavailable', 'Bit Flows execution is blocked because exact FlowLog/FlowHistory readback is unavailable.' );
		if ( ! class_exists( 'MAD4B_SCP_Durable_Execution' ) ) return new WP_Error( 'mad4b_bitflows_durable_execution_unavailable', 'Bit Flows execution requires MAD4B durable idempotency.' );
		if ( ! defined( 'MAD4B_MCP_BITFLOWS_EXECUTION_ENABLED' ) || true !== MAD4B_MCP_BITFLOWS_EXECUTION_ENABLED ) return new WP_Error( 'mad4b_bitflows_execution_disabled', 'Bit Flows execution is disabled until MAD4B_MCP_BITFLOWS_EXECUTION_ENABLED is explicitly enabled.' );
		$flow_class = 'BitApps\\Pi\\Model\\Flow';
		$executor = 'BitApps\\Pi\\src\\Flow\\FlowExecutor';
		$id = absint( isset( $input['flow_id'] ) ? $input['flow_id'] : 0 );
		$idempotency_key = isset( $input['idempotency_key'] ) ? trim( (string) $input['idempotency_key'] ) : '';
		if ( ! preg_match( '/^[A-Za-z0-9._:-]{8,191}$/', $idempotency_key ) ) return new WP_Error( 'mad4b_bitflows_idempotency_key_invalid', 'Bit Flows execution requires a stable idempotency_key using 8..191 safe characters.' );
		try {
			$flow = $flow_class::select( array( 'id', 'title', 'map', 'settings', 'listener_type', 'is_hook_capture', 'is_active', 'trigger_type' ) )->findOne( array( 'id' => $id ) );
			if ( ! $flow ) return new WP_Error( 'mad4b_bitflows_flow_missing', 'Flow not found.' );
			if ( 1 !== (int) $flow->is_active ) return new WP_Error( 'mad4b_bitflows_flow_inactive', 'Inactive flows cannot be run through the control plane.' );

			$fingerprint = $this->flow_fingerprint( $id, $flow );
			if ( is_wp_error( $fingerprint ) ) return $fingerprint;
			if ( ! hash_equals( $fingerprint, strtolower( trim( isset( $input['expected_flow_sha256'] ) ? (string) $input['expected_flow_sha256'] : '' ) ) ) ) return new WP_Error( 'mad4b_bitflows_stale_flow', 'Flow definition changed since it was reviewed.', array( 'current_flow_sha256' => $fingerprint ) );
			$expected_plan_sha = isset( $input['expected_plan_sha256'] ) ? strtolower( trim( (string) $input['expected_plan_sha256'] ) ) : '';
			if ( ! preg_match( '/^[a-f0-9]{64}$/', $expected_plan_sha ) ) return new WP_Error( 'mad4b_bitflows_plan_digest_required', 'Bit Flows execution requires expected_plan_sha256 from the reviewed workflow plan.' );
			if ( ! class_exists( 'MAD4B_SCP_Workflow_Providers' ) ) return new WP_Error( 'mad4b_workflow_planner_unavailable', 'Workflow planner is unavailable.' );
			$reviewed_plan = MAD4B_SCP_Workflow_Providers::plan( array( 'provider' => 'bitflows', 'operation' => 'execute', 'workflow_ref' => (string) $id, 'expected_workflow_sha256' => $fingerprint, 'reason' => isset( $input['reason'] ) ? (string) $input['reason'] : '' ) );
			if ( is_wp_error( $reviewed_plan ) ) return $reviewed_plan;
			if ( ! hash_equals( (string) $reviewed_plan['plan_sha256'], $expected_plan_sha ) ) return new WP_Error( 'mad4b_bitflows_plan_changed', 'Workflow execution plan changed since approval.', array( 'current_plan_sha256' => $reviewed_plan['plan_sha256'], 'expected_plan_sha256' => $expected_plan_sha ) );
			if ( ! (bool) apply_filters( 'mad4b_scp_bitflows_flow_allowed', false, $id, $fingerprint, $flow, get_current_user_id() ) ) return new WP_Error( 'mad4b_bitflows_flow_policy_denied', 'Flow execution requires an explicit per-flow allowlist policy.' );

			$trigger_data = isset( $input['trigger_data'] ) && is_array( $input['trigger_data'] ) ? $input['trigger_data'] : array();
			if ( array_key_exists( self::CORRELATION_KEY, $trigger_data ) ) return new WP_Error( 'mad4b_bitflows_reserved_trigger_key', 'The reserved MAD4B execution-correlation trigger key may not be supplied by callers.' );
			$site_uuid = class_exists( 'MAD4B_SCP_Site_Profile' ) ? strtolower( trim( (string) MAD4B_SCP_Site_Profile::site_uuid() ) ) : '';
			if ( ! preg_match( '/^[a-f0-9-]{36}$/', $site_uuid ) ) return new WP_Error( 'mad4b_bitflows_site_identity_unavailable', 'Bit Flows durable execution requires the exact enrolled Site Profile identity.' );

			$request_material = array(
				'contract' => 'mad4b.bitflows-execution-request.v1',
				'site_uuid' => $site_uuid,
				'flow_id' => $id,
				'flow_sha256' => $fingerprint,
				'plan_sha256' => $expected_plan_sha,
				'trigger_data' => $trigger_data,
			);
			$request_sha256 = self::stable_digest( $request_material );
			$scope_key = MAD4B_SCP_Durable_Execution::scope_key( $site_uuid, 'workflow.execute', 'bitflows.execute', 'flow:' . $id );
			$correlation_token = self::correlation_token( $scope_key, $idempotency_key, $request_sha256 );
			$reason = sanitize_text_field( isset( $input['reason'] ) ? $input['reason'] : '' );

			$claim = MAD4B_SCP_Durable_Execution::begin_idempotency( $scope_key, $idempotency_key, $request_sha256 );
			if ( is_wp_error( $claim ) ) {
				if ( in_array( $claim->get_error_code(), array( 'mad4b_idempotency_in_progress', 'mad4b_idempotency_reconciliation_required' ), true ) ) {
					$readback = self::readback_by_correlation( $id, $correlation_token, $request_sha256 );
					if ( ! is_wp_error( $readback ) && ! empty( $readback['found'] ) ) {
						return self::complete_from_readback( $scope_key, $idempotency_key, $request_sha256, $readback, true );
					}
				}
				return $claim;
			}
			if ( ! empty( $claim['replayed'] ) ) {
				$result = isset( $claim['result'] ) && is_array( $claim['result'] ) ? $claim['result'] : array();
				$result['idempotency_replayed'] = true;
				$result['idempotency_result_sha256'] = isset( $claim['result_sha256'] ) ? (string) $claim['result_sha256'] : '';
				return $result;
			}

			// A deterministic correlation pre-read prevents a lost local idempotency row
			// from causing a duplicate provider execution.
			$existing = self::readback_by_correlation( $id, $correlation_token, $request_sha256 );
			if ( ! is_wp_error( $existing ) && ! empty( $existing['found'] ) ) {
				return self::complete_from_readback( $scope_key, $idempotency_key, $request_sha256, $existing, true );
			}
			if ( is_wp_error( $existing ) && 'mad4b_bitflows_execution_not_found' !== $existing->get_error_code() ) return $existing;

			$provider_trigger = $trigger_data;
			$provider_trigger[ self::CORRELATION_KEY ] = array(
				'contract' => self::CORRELATION_CONTRACT,
				'correlation_token' => $correlation_token,
				'request_sha256' => $request_sha256,
				'plan_sha256' => $expected_plan_sha,
			);

			MAD4B_SCP_Audit::record(
				'bitflows/run-flow',
				array(
					'flow_id' => $id,
					'flow_sha256' => $fingerprint,
					'plan_sha256' => $expected_plan_sha,
					'reason' => $reason,
					'trigger_keys' => array_keys( $trigger_data ),
					'idempotency_scope_sha256' => $scope_key,
					'idempotency_key_sha256' => hash( 'sha256', $idempotency_key ),
					'request_sha256' => $request_sha256,
					'correlation_sha256' => hash( 'sha256', $correlation_token ),
				),
				'attempt'
			);

			try {
				$executor_result = $executor::execute( $flow, $provider_trigger );
			} catch ( Throwable $provider_error ) {
				$readback = self::readback_by_correlation( $id, $correlation_token, $request_sha256 );
				if ( ! is_wp_error( $readback ) && ! empty( $readback['found'] ) ) {
					return self::complete_from_readback( $scope_key, $idempotency_key, $request_sha256, $readback, false );
				}
				MAD4B_SCP_Audit::record( 'bitflows/run-flow', array( 'flow_id' => $id, 'request_sha256' => $request_sha256, 'error_type' => get_class( $provider_error ), 'retry_safe' => false ), 'failure' );
				return new WP_Error( 'mad4b_bitflows_execution_uncertain', 'Bit Flows execution threw before exact provider readback could prove whether the side effect started. Automatic retry is blocked by the pending idempotency record.', array( 'retry_safe' => false, 'idempotency_pending' => true ) );
			}

			$readback = self::readback_by_correlation( $id, $correlation_token, $request_sha256 );
			if ( is_wp_error( $readback ) ) {
				MAD4B_SCP_Audit::record( 'bitflows/run-flow', array( 'flow_id' => $id, 'request_sha256' => $request_sha256, 'executor_result' => (bool) $executor_result, 'retry_safe' => false, 'readback_verified' => false ), 'failure' );
				return new WP_Error( 'mad4b_bitflows_execution_readback_missing', 'Bit Flows returned without a uniquely correlated FlowHistory readback. Automatic retry is blocked until provider state is reconciled.', array( 'retry_safe' => false, 'idempotency_pending' => true, 'cause' => $readback->get_error_code() ) );
			}

			$result = self::result_from_readback( $readback, false );
			$completed = MAD4B_SCP_Durable_Execution::complete_idempotency( $claim, $result );
			if ( is_wp_error( $completed ) ) {
				$reconciled = self::complete_from_readback( $scope_key, $idempotency_key, $request_sha256, $readback, false );
				if ( ! is_wp_error( $reconciled ) ) return $reconciled;
				return new WP_Error( 'mad4b_bitflows_idempotency_completion_uncertain', 'Provider execution was read back, but durable completion could not be committed. Re-execution remains blocked.', array( 'retry_safe' => false, 'provider_execution_ref' => $readback['provider_execution_ref'] ) );
			}

			MAD4B_SCP_Audit::record( 'bitflows/run-flow', array( 'flow_id' => $id, 'request_sha256' => $request_sha256, 'provider_execution_ref' => $readback['provider_execution_ref'], 'provider_status' => $readback['provider_status'], 'readback_verified' => true, 'queued' => true ) );
			$result['idempotency_result_sha256'] = isset( $completed['result_sha256'] ) ? (string) $completed['result_sha256'] : '';
			return $result;
		} catch ( Throwable $e ) {
			MAD4B_SCP_Audit::record( 'bitflows/run-flow', array( 'flow_id' => $id, 'error_type' => get_class( $e ) ), 'failure' );
			return new WP_Error( 'mad4b_bitflows_execution_failed', $e->getMessage() );
		}
	}

	public static function verify_durable_reconciliation( $verified, $kind, $context ) {
		if ( true === $verified ) return true;
		if ( 'idempotency_completion' !== sanitize_key( (string) $kind ) || ! is_array( $context ) ) return $verified;
		$result = isset( $context['result'] ) && is_array( $context['result'] ) ? $context['result'] : array();
		if ( 'bit_pi' !== ( isset( $result['provider_id'] ) ? (string) $result['provider_id'] : '' ) ) return $verified;
		$ref = isset( $context['reconciliation_ref'] ) ? trim( (string) $context['reconciliation_ref'] ) : '';
		if ( ! preg_match( '/^bitflows:flow-history:([1-9][0-9]*):([1-9][0-9]*):([a-f0-9]{64})$/', $ref, $m ) ) return false;
		$flow_id = (int) $m[1];
		$history_id = (int) $m[2];
		$token = (string) $m[3];
		$scope_key = isset( $context['scope_key'] ) ? strtolower( trim( (string) $context['scope_key'] ) ) : '';
		$idempotency_key = isset( $context['idempotency_key'] ) ? trim( (string) $context['idempotency_key'] ) : '';
		$request_sha256 = isset( $context['request_sha256'] ) ? strtolower( trim( (string) $context['request_sha256'] ) ) : '';
		if ( ! hash_equals( self::correlation_token( $scope_key, $idempotency_key, $request_sha256 ), $token ) ) return false;
		$readback = self::readback_by_correlation( $flow_id, $token, $request_sha256 );
		if ( is_wp_error( $readback ) || empty( $readback['found'] ) || (int) $readback['flow_history_id'] !== $history_id ) return false;
		if ( ! isset( $result['provider_execution_ref'] ) || ! hash_equals( (string) $result['provider_execution_ref'], (string) $readback['provider_execution_ref'] ) ) return false;
		if ( ! isset( $result['request_sha256'] ) || ! hash_equals( (string) $result['request_sha256'], $request_sha256 ) ) return false;
		return true;
	}

	private static function complete_from_readback( $scope_key, $idempotency_key, $request_sha256, array $readback, $replayed ) {
		$result = self::result_from_readback( $readback, (bool) $replayed );
		$reconciliation_ref = sprintf(
			'bitflows:flow-history:%d:%d:%s',
			(int) $readback['flow_id'],
			(int) $readback['flow_history_id'],
			(string) $readback['correlation_token']
		);
		$completion = MAD4B_SCP_Durable_Execution::complete_idempotency_from_reconciliation(
			$scope_key,
			$idempotency_key,
			$request_sha256,
			$reconciliation_ref,
			$result
		);
		if ( is_wp_error( $completion ) ) return $completion;
		$result = isset( $completion['result'] ) && is_array( $completion['result'] ) ? $completion['result'] : $result;
		$result['idempotency_replayed'] = (bool) $replayed || ! empty( $completion['replayed'] );
		$result['idempotency_reconciled'] = ! empty( $completion['reconciled'] );
		$result['idempotency_result_sha256'] = isset( $completion['result_sha256'] ) ? (string) $completion['result_sha256'] : '';
		return $result;
	}

	private static function result_from_readback( array $readback, $replayed ) {
		return array(
			'contract' => 'mad4b.bitflows-execution-result.v1',
			'provider_id' => 'bit_pi',
			'flow_id' => (int) $readback['flow_id'],
			'provider_execution_ref' => (string) $readback['provider_execution_ref'],
			'provider_status' => (string) $readback['provider_status'],
			'request_sha256' => (string) $readback['request_sha256'],
			'readback_verified' => true,
			'queued' => true,
			'idempotency_replayed' => (bool) $replayed,
		);
	}

	private static function readback_by_correlation( $flow_id, $correlation_token, $request_sha256 ) {
		$flow_id = absint( $flow_id );
		$correlation_token = strtolower( trim( (string) $correlation_token ) );
		$request_sha256 = strtolower( trim( (string) $request_sha256 ) );
		if ( $flow_id < 1 || ! preg_match( '/^[a-f0-9]{64}$/', $correlation_token ) || ! preg_match( '/^[a-f0-9]{64}$/', $request_sha256 ) ) return new WP_Error( 'mad4b_bitflows_correlation_invalid', 'Bit Flows correlation identity is invalid.' );
		if ( ! class_exists( 'BitApps\\Pi\\Model\\FlowLog' ) || ! class_exists( 'BitApps\\Pi\\Model\\FlowHistory' ) ) return new WP_Error( 'mad4b_bitflows_readback_unavailable', 'Bit Flows FlowLog/FlowHistory readback is unavailable.' );
		$log_class = 'BitApps\\Pi\\Model\\FlowLog';
		$history_class = 'BitApps\\Pi\\Model\\FlowHistory';
		try {
			$rows = $log_class::where( 'node_id', $flow_id . '-1' )->desc()->take( 200 )->select( array( 'id', 'flow_history_id', 'node_id', 'status', 'output', 'created_at' ) )->get();
			$normalized = json_decode( wp_json_encode( $rows ), true );
			$normalized = is_array( $normalized ) ? $normalized : array();
			$matches = array();
			foreach ( $normalized as $row ) {
				if ( ! is_array( $row ) || empty( $row['flow_history_id'] ) ) continue;
				$output = isset( $row['output'] ) ? self::decode_provider_json( $row['output'] ) : null;
				if ( ! is_array( $output ) || ! isset( $output[ self::CORRELATION_KEY ] ) || ! is_array( $output[ self::CORRELATION_KEY ] ) ) continue;
				$meta = $output[ self::CORRELATION_KEY ];
				if ( self::CORRELATION_CONTRACT !== ( isset( $meta['contract'] ) ? (string) $meta['contract'] : '' ) ) continue;
				if ( ! isset( $meta['correlation_token'] ) || ! hash_equals( $correlation_token, strtolower( (string) $meta['correlation_token'] ) ) ) continue;
				if ( ! isset( $meta['request_sha256'] ) || ! hash_equals( $request_sha256, strtolower( (string) $meta['request_sha256'] ) ) ) continue;
				$matches[] = $row;
			}
			if ( 1 !== count( $matches ) ) {
				$code = count( $matches ) > 1 ? 'mad4b_bitflows_execution_correlation_ambiguous' : 'mad4b_bitflows_execution_not_found';
				return new WP_Error( $code, count( $matches ) > 1 ? 'Bit Flows correlation matched multiple execution logs.' : 'No Bit Flows execution log matches the durable correlation identity.', array( 'match_count' => count( $matches ) ) );
			}
			$history_id = absint( $matches[0]['flow_history_id'] );
			$history = $history_class::findOne( array( 'id' => $history_id ) );
			$history_row = json_decode( wp_json_encode( $history ), true );
			if ( ! is_array( $history_row ) || $flow_id !== absint( isset( $history_row['flow_id'] ) ? $history_row['flow_id'] : 0 ) ) return new WP_Error( 'mad4b_bitflows_execution_history_mismatch', 'Correlated FlowLog does not resolve to the expected FlowHistory.' );
			$status = isset( $history_row['status'] ) ? sanitize_key( (string) $history_row['status'] ) : 'unknown';
			return array(
				'contract' => self::READBACK_CONTRACT,
				'found' => true,
				'flow_id' => $flow_id,
				'flow_history_id' => $history_id,
				'provider_execution_ref' => 'bitflows:flow-history:' . $flow_id . ':' . $history_id,
				'provider_status' => $status,
				'request_sha256' => $request_sha256,
				'correlation_token' => $correlation_token,
				'log_id' => absint( isset( $matches[0]['id'] ) ? $matches[0]['id'] : 0 ),
			);
		} catch ( Throwable $e ) {
			return new WP_Error( 'mad4b_bitflows_execution_readback_failed', 'Unable to read back Bit Flows execution correlation.', array( 'cause' => get_class( $e ) ) );
		}
	}

	private static function decode_provider_json( $value ) {
		$current = $value;
		for ( $i = 0; $i < 2; $i++ ) {
			if ( is_array( $current ) ) return $current;
			if ( ! is_string( $current ) || '' === trim( $current ) ) return null;
			$decoded = json_decode( $current, true );
			if ( JSON_ERROR_NONE !== json_last_error() ) return null;
			$current = $decoded;
		}
		return is_array( $current ) ? $current : null;
	}

	private static function correlation_token( $scope_key, $idempotency_key, $request_sha256 ) {
		$scope_key = strtolower( trim( (string) $scope_key ) );
		$idempotency_key = trim( (string) $idempotency_key );
		$request_sha256 = strtolower( trim( (string) $request_sha256 ) );
		return hash( 'sha256', self::CORRELATION_CONTRACT . "\0" . $scope_key . "\0" . $idempotency_key . "\0" . $request_sha256 );
	}

	private static function stable_digest( $value ) {
		$canonical = self::canonicalize( $value );
		$encoded = wp_json_encode( $canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return is_string( $encoded ) ? hash( 'sha256', $encoded ) : '';
	}

	private static function canonicalize( $value ) {
		if ( is_array( $value ) ) {
			$is_list = array_keys( $value ) === range( 0, count( $value ) - 1 );
			if ( $is_list ) return array_map( array( __CLASS__, 'canonicalize' ), $value );
			$keys = array_keys( $value );
			sort( $keys, SORT_STRING );
			$out = array();
			foreach ( $keys as $key ) $out[ (string) $key ] = self::canonicalize( $value[ $key ] );
			return $out;
		}
		if ( is_object( $value ) ) return self::canonicalize( get_object_vars( $value ) );
		if ( is_string( $value ) || is_int( $value ) || is_float( $value ) || is_bool( $value ) || null === $value ) return $value;
		return (string) $value;
	}

	private function flow_fingerprint( $flow_id, $flow = null ) {
		$flow_class = 'BitApps\\Pi\\Model\\Flow';
		try {
			if ( ! $flow ) $flow = $flow_class::select( array( 'id', 'title', 'map', 'settings', 'listener_type', 'is_hook_capture', 'is_active', 'trigger_type' ) )->findOne( array( 'id' => absint( $flow_id ) ) );
			if ( ! $flow ) return new WP_Error( 'mad4b_bitflows_flow_missing', 'Flow not found.' );
			$payload = array( 'flow' => $this->normalize( $flow ), 'nodes' => array() );
			if ( class_exists( 'BitApps\\Pi\\Model\\FlowNode' ) ) {
				$node_class = 'BitApps\\Pi\\Model\\FlowNode';
				$nodes = $node_class::where( 'flow_id', absint( $flow_id ) )->select( array( 'id', 'node_id', 'flow_id', 'app_slug', 'machine_slug', 'field_mapping', 'data', 'variables' ) )->get();
				$payload['nodes'] = $this->normalize( $nodes );
				if ( is_array( $payload['nodes'] ) ) usort( $payload['nodes'], function ( $a, $b ) { return (int) ( isset( $a['id'] ) ? $a['id'] : 0 ) <=> (int) ( isset( $b['id'] ) ? $b['id'] : 0 ); } );
			}
			$encoded = wp_json_encode( $payload );
			if ( false === $encoded ) return new WP_Error( 'mad4b_bitflows_fingerprint_failed', 'Unable to fingerprint Flow definition.' );
			return hash( 'sha256', $encoded );
		} catch ( Throwable $e ) { return new WP_Error( 'mad4b_bitflows_fingerprint_failed', $e->getMessage() ); }
	}
	private function redact( $value ) {
		if ( ! is_array( $value ) ) return $value;
		$result = array();
		foreach ( $value as $key => $item ) {
			$name = strtolower( (string) $key );
			if ( preg_match( '/(?:pass(?:word)?|secret|token|api[_-]?key|auth|credential|private[_-]?key|access[_-]?key|refresh[_-]?token|cookie|authorization)/i', $name ) ) {
				$result[ $key ] = '[REDACTED]';
			} else {
				$result[ $key ] = is_array( $item ) ? $this->redact( $item ) : $item;
			}
		}
		return $result;
	}
}
MAD4B_SCP_BitFlows_Adapter::boot();
