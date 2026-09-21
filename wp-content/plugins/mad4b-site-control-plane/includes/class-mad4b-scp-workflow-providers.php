<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Provider-neutral workflow planning and capability discovery.
 *
 * This class intentionally owns no provider mutation. It turns a MAD4B workflow
 * intent into a deterministic provider handoff plan and exposes capability gaps.
 * The selected provider ability remains the only executable mutation surface and
 * therefore keeps its own NHI, approval, budget, certification and audit gates.
 */
final class MAD4B_SCP_Workflow_Providers {
	const CONTRACT = 'mad4b.workflow-provider-contracts.v1';
	const PLAN_CONTRACT = 'mad4b.workflow-operation-plan.v1';
	const CONFIG = 'config/workflow-provider-contracts.json';

	private static $config = null;

	public static function boot() {
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 34 );
	}

	public static function register_abilities() {
		if ( ! function_exists( 'wp_register_ability' ) ) return;
		if ( function_exists( 'wp_register_ability_category' ) ) {
			wp_register_ability_category(
				'mad4b-workflows',
				array(
					'label' => 'MAD4B Workflows',
					'description' => 'Provider-neutral governed workflow planning and provider capability discovery.',
				)
			);
		}

		if ( ! wp_has_ability( 'mad4b/workflow-provider-status' ) ) {
			wp_register_ability(
				'mad4b/workflow-provider-status',
				array(
					'label' => 'Workflow Provider Status',
					'description' => 'Read provider-neutral workflow capability coverage and runtime readiness.',
					'category' => 'mad4b-workflows',
					'execute_callback' => array( __CLASS__, 'status' ),
					'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' ),
					'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
					'meta' => self::readonly_meta( 'read' ),
				)
			);
		}

		if ( ! wp_has_ability( 'mad4b/workflow-plan' ) ) {
			wp_register_ability(
				'mad4b/workflow-plan',
				array(
					'label' => 'Plan Workflow Operation',
					'description' => 'Create a deterministic non-authorizing workflow provider handoff plan.',
					'category' => 'mad4b-workflows',
					'execute_callback' => array( __CLASS__, 'plan' ),
					'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' ),
					'input_schema' => array(
						'type' => 'object',
						'properties' => array(
							'provider' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 64 ),
							'operation' => array(
								'type' => 'string',
								'enum' => array( 'list', 'get', 'execution_status', 'execute', 'create', 'enable', 'disable', 'retry', 'cancel' ),
							),
							'workflow_ref' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 191 ),
							'expected_workflow_sha256' => array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64 ),
							'reason' => array( 'type' => 'string', 'minLength' => 3, 'maxLength' => 500 ),
						),
						'required' => array( 'operation', 'reason' ),
						'additionalProperties' => false,
					),
					'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
					'meta' => self::readonly_meta( 'read' ),
				)
			);
		}
	}

	public static function status() {
		$config = self::config();
		if ( is_wp_error( $config ) ) return $config;

		$providers = array();
		foreach ( $config['providers'] as $provider_id => $definition ) {
			$provider_id = sanitize_key( (string) $provider_id );
			$adapter = self::adapter( $definition );
			$adapter_status = is_object( $adapter ) && method_exists( $adapter, 'status' ) ? $adapter->status() : array();
			$operations = array();
			$adapter_available = is_object( $adapter ) && method_exists( $adapter, 'is_available' ) ? (bool) $adapter->is_available() : false;
			$provider_certification = isset( $adapter_status['provider_certification'] ) && is_array( $adapter_status['provider_certification'] ) ? $adapter_status['provider_certification'] : array();
			$provider_certified = ! empty( $provider_certification['runtime_contract_ok'] );

			foreach ( $definition['operations'] as $operation => $operation_definition ) {
				$ability = isset( $operation_definition['ability'] ) && is_string( $operation_definition['ability'] )
					? trim( $operation_definition['ability'] )
					: '';
				$registered = '' !== $ability && function_exists( 'wp_has_ability' ) && wp_has_ability( $ability );
				$requires = isset( $operation_definition['requires'] ) && is_array( $operation_definition['requires'] ) ? array_values( $operation_definition['requires'] ) : array();
				$requires_provider_certification = in_array( 'provider_capability_certified', $requires, true );
				$config_blocker = isset( $operation_definition['blocker'] ) ? sanitize_key( (string) $operation_definition['blocker'] ) : '';
				$blocker = $config_blocker;
				if ( '' === $blocker && ! $registered ) $blocker = 'ability_not_registered';
				if ( '' === $blocker && ! $adapter_available ) $blocker = 'provider_runtime_unavailable';
				if ( '' === $blocker && $requires_provider_certification && ! $provider_certified ) $blocker = 'provider_capability_not_certified';
				$operations[ sanitize_key( (string) $operation ) ] = array(
					'ability' => $ability,
					'risk' => isset( $operation_definition['risk'] ) ? sanitize_key( (string) $operation_definition['risk'] ) : 'unknown',
					'registered' => $registered,
					'provider_available' => $adapter_available,
					'provider_certified' => $provider_certified,
					'state' => '' === $blocker ? 'available' : ( 'unavailable' === ( isset( $operation_definition['state'] ) ? sanitize_key( (string) $operation_definition['state'] ) : '' ) ? 'unavailable' : 'blocked' ),
					'blocker' => $blocker,
					'requires' => $requires,
				);
			}

			$providers[ $provider_id ] = array(
				'adapter_id' => isset( $definition['adapter_id'] ) ? sanitize_key( (string) $definition['adapter_id'] ) : '',
				'provider_key' => isset( $definition['provider_key'] ) ? sanitize_key( (string) $definition['provider_key'] ) : '',
				'role' => isset( $definition['role'] ) ? sanitize_key( (string) $definition['role'] ) : 'execution_provider',
				'adapter_available' => $adapter_available,
				'provider_certification' => $provider_certification,
				'capability_certification' => isset( $adapter_status['capability_certification'] ) ? $adapter_status['capability_certification'] : array(),
				'operations' => $operations,
				'implementation_policy' => isset( $definition['implementation_policy'] ) && is_array( $definition['implementation_policy'] ) ? $definition['implementation_policy'] : array(),
			);
		}

		return array(
			'contract' => self::CONTRACT,
			'default_provider' => isset( $config['default_provider'] ) ? sanitize_key( (string) $config['default_provider'] ) : '',
			'source_of_truth' => isset( $config['source_of_truth'] ) ? sanitize_key( (string) $config['source_of_truth'] ) : 'mad4b_workflow_plan',
			'provider_selection' => isset( $config['provider_selection'] ) ? sanitize_key( (string) $config['provider_selection'] ) : 'explicit_or_default',
			'providers' => $providers,
			'provider_count' => count( $providers ),
			'mutation_performed' => false,
			'authority_created' => false,
		);
	}

	public static function plan( $input ) {
		$input = is_array( $input ) ? $input : array();
		$config = self::config();
		if ( is_wp_error( $config ) ) return $config;

		$provider = isset( $input['provider'] ) && '' !== trim( (string) $input['provider'] )
			? sanitize_key( (string) $input['provider'] )
			: sanitize_key( (string) $config['default_provider'] );
		$operation = isset( $input['operation'] ) ? sanitize_key( (string) $input['operation'] ) : '';
		$reason = isset( $input['reason'] ) ? sanitize_text_field( (string) $input['reason'] ) : '';

		if ( ! isset( $config['providers'][ $provider ] ) ) return new WP_Error( 'mad4b_workflow_provider_unknown', 'Requested workflow provider is not registered.' );
		$definition = $config['providers'][ $provider ];
		if ( ! isset( $definition['operations'][ $operation ] ) || ! is_array( $definition['operations'][ $operation ] ) ) {
			return new WP_Error( 'mad4b_workflow_operation_unknown', 'Requested workflow operation is not defined for this provider.' );
		}

		$operation_definition = $definition['operations'][ $operation ];
		$ability = isset( $operation_definition['ability'] ) && is_string( $operation_definition['ability'] )
			? trim( $operation_definition['ability'] )
			: '';
		$registered = '' !== $ability && function_exists( 'wp_has_ability' ) && wp_has_ability( $ability );
		$adapter = self::adapter( $definition );
		$adapter_available = is_object( $adapter ) && method_exists( $adapter, 'is_available' ) ? (bool) $adapter->is_available() : false;
		$adapter_status = is_object( $adapter ) && method_exists( $adapter, 'status' ) ? $adapter->status() : array();
		$provider_certification = isset( $adapter_status['provider_certification'] ) && is_array( $adapter_status['provider_certification'] ) ? $adapter_status['provider_certification'] : array();
		$provider_certified = ! empty( $provider_certification['runtime_contract_ok'] );
		$requires = isset( $operation_definition['requires'] ) && is_array( $operation_definition['requires'] ) ? array_values( $operation_definition['requires'] ) : array();
		$requires_provider_certification = in_array( 'provider_capability_certified', $requires, true );
		$blocker = isset( $operation_definition['blocker'] ) ? sanitize_key( (string) $operation_definition['blocker'] ) : '';
		if ( '' === $blocker && ! $registered ) $blocker = 'ability_not_registered';
		if ( '' === $blocker && ! $adapter_available ) $blocker = 'provider_runtime_unavailable';
		if ( '' === $blocker && $requires_provider_certification && ! $provider_certified ) $blocker = 'provider_capability_not_certified';
		$workflow_ref = isset( $input['workflow_ref'] ) ? sanitize_text_field( (string) $input['workflow_ref'] ) : '';
		$expected_sha = isset( $input['expected_workflow_sha256'] ) ? strtolower( trim( (string) $input['expected_workflow_sha256'] ) ) : '';

		if ( in_array( $operation, array( 'get', 'execution_status', 'execute', 'enable', 'disable', 'retry', 'cancel' ), true ) && '' === $workflow_ref ) {
			return new WP_Error( 'mad4b_workflow_ref_required', 'This workflow operation requires workflow_ref.' );
		}
		if ( 'execute' === $operation && ! preg_match( '/^[a-f0-9]{64}$/', $expected_sha ) ) {
			return new WP_Error( 'mad4b_workflow_fingerprint_required', 'Execute planning requires expected_workflow_sha256.' );
		}

		$provider_input = self::provider_input_template( $provider, $operation, $workflow_ref, $expected_sha, $reason );
		if ( is_wp_error( $provider_input ) ) return $provider_input;

		$plan = array(
			'contract' => self::PLAN_CONTRACT,
			'provider' => $provider,
			'provider_key' => isset( $definition['provider_key'] ) ? sanitize_key( (string) $definition['provider_key'] ) : '',
			'operation' => $operation,
			'workflow_ref' => $workflow_ref,
			'expected_workflow_sha256' => $expected_sha,
			'ability' => $ability,
			'risk' => isset( $operation_definition['risk'] ) ? sanitize_key( (string) $operation_definition['risk'] ) : 'unknown',
			'ability_registered' => $registered,
			'provider_available' => $adapter_available,
			'provider_certified' => $provider_certified,
			'provider_certification' => $provider_certification,
			'execution_ready' => '' === $blocker,
			'blocker' => $blocker,
			'requires' => $requires,
			'provider_input_template' => $provider_input,
			'reason' => $reason,
			'non_authorizing' => true,
			'mutation_performed' => false,
			'next_action' => '' === $blocker ? 'invoke_exact_provider_ability_through_governed_authority' : 'close_provider_capability_certification_gap',
		);
		$encoded = wp_json_encode( self::canonicalize( $plan ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $encoded ) return new WP_Error( 'mad4b_workflow_plan_encoding_failed', 'Unable to encode deterministic workflow plan.' );
		$plan['plan_sha256'] = hash( 'sha256', $encoded );

		return $plan;
	}

	private static function provider_input_template( $provider, $operation, $workflow_ref, $expected_sha, $reason ) {
		if ( 'bitflows' !== $provider ) return array( 'workflow_ref' => $workflow_ref, 'reason' => $reason );
		if ( '' !== $workflow_ref && ! ctype_digit( (string) $workflow_ref ) ) {
			return new WP_Error( 'mad4b_bitflows_workflow_ref_invalid', 'Bit Flows workflow_ref must be the numeric flow ID.' );
		}
		$flow_id = '' === $workflow_ref ? 0 : absint( $workflow_ref );
		if ( 'list' === $operation ) return array( 'limit' => 50 );
		if ( 'get' === $operation ) return array( 'flow_id' => $flow_id );
		if ( 'execution_status' === $operation ) return array( 'flow_id' => $flow_id, 'limit' => 20 );
		if ( 'execute' === $operation ) {
			return array(
				'flow_id' => $flow_id,
				'expected_flow_sha256' => $expected_sha,
				'trigger_data' => array(),
				'reason' => $reason,
			);
		}
		return array( 'flow_id' => $flow_id, 'reason' => $reason );
	}

	private static function adapter( array $definition ) {
		if ( ! class_exists( 'MAD4B_SCP_Adapter_Registry' ) ) return null;
		$registry = MAD4B_SCP_Adapter_Registry::instance();
		$registry->register_defaults();
		$id = isset( $definition['adapter_id'] ) ? sanitize_key( (string) $definition['adapter_id'] ) : '';
		return '' !== $id ? $registry->get( $id ) : null;
	}

	private static function config() {
		if ( is_array( self::$config ) ) return self::$config;
		$path = MAD4B_SCP_DIR . self::CONFIG;
		if ( ! is_readable( $path ) ) return new WP_Error( 'mad4b_workflow_provider_config_missing', 'Workflow provider contract configuration is unavailable.' );
		$raw = file_get_contents( $path );
		$decoded = false === $raw ? null : json_decode( $raw, true );
		if ( ! is_array( $decoded ) || self::CONTRACT !== ( isset( $decoded['contract'] ) ? (string) $decoded['contract'] : '' ) || empty( $decoded['providers'] ) || ! is_array( $decoded['providers'] ) ) {
			return new WP_Error( 'mad4b_workflow_provider_config_invalid', 'Workflow provider contract configuration is invalid.' );
		}
		self::$config = $decoded;
		return self::$config;
	}

	private static function readonly_meta( $surface ) {
		return array(
			'public' => false,
			'show_in_rest' => false,
			'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => sanitize_key( (string) $surface ) ),
			'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
		);
	}

	private static function canonicalize( $value ) {
		if ( ! is_array( $value ) ) return $value;
		$is_list = empty( $value ) || array_keys( $value ) === range( 0, count( $value ) - 1 );
		if ( $is_list ) {
			$out = array();
			foreach ( $value as $item ) $out[] = self::canonicalize( $item );
			return $out;
		}
		ksort( $value, SORT_STRING );
		foreach ( $value as $key => $item ) $value[ $key ] = self::canonicalize( $item );
		return $value;
	}
}
