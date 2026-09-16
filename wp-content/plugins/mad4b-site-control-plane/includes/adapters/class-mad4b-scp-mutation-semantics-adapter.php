<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Cross-cutting fail-closed registration guards for governed mutation planning.
 *
 * These guards do not create authority and do not execute a target mutation.
 * They only ensure that an approval plan cannot be created for target input
 * which the registered target ability itself would reject at execution time.
 */
final class MAD4B_SCP_Approval_Plan_Target_Validation {
	private static $booted = false;
	private static $translation_writes = array(
		'mad4b/translation-set-post-language',
		'mad4b/translation-link-posts',
	);

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;
		add_filter( 'wp_register_ability_args', array( __CLASS__, 'harden_registered_ability' ), 175, 2 );
		// WordPress 7.1+ exposes this validation filter. The schema + approval-plan
		// wrapper remain authoritative on 6.9/7.0 where the filter is absent.
		add_filter( 'wp_ability_validate_input', array( __CLASS__, 'validate_translation_provider' ), 25, 3 );
	}

	public static function harden_registered_ability( $args, $ability_name ) {
		$ability_name = (string) $ability_name;
		if ( in_array( $ability_name, self::$translation_writes, true ) ) {
			$args = self::bind_translation_provider_schema( is_array( $args ) ? $args : array() );
		}
		if ( 'mad4b/approval-plan' === $ability_name && is_array( $args ) && ! empty( $args['execute_callback'] ) && is_callable( $args['execute_callback'] ) ) {
			$callback = $args['execute_callback'];
			$args['execute_callback'] = function ( $input = array() ) use ( $callback ) {
				$guard = self::validate_planned_target( is_array( $input ) ? $input : array() );
				if ( is_wp_error( $guard ) ) return $guard;
				return call_user_func( $callback, $input );
			};
		}
		return $args;
	}

	private static function bind_translation_provider_schema( array $args ) {
		if ( empty( $args['input_schema'] ) || ! is_array( $args['input_schema'] ) ) return $args;
		if ( empty( $args['input_schema']['properties'] ) || ! is_array( $args['input_schema']['properties'] ) ) $args['input_schema']['properties'] = array();
		$args['input_schema']['properties']['provider'] = array(
			'type' => 'string',
			'enum' => array( 'wpml', 'polylang' ),
		);
		$required = isset( $args['input_schema']['required'] ) && is_array( $args['input_schema']['required'] ) ? $args['input_schema']['required'] : array();
		if ( ! in_array( 'provider', $required, true ) ) $required[] = 'provider';
		$args['input_schema']['required'] = array_values( array_unique( $required ) );
		return $args;
	}

	public static function validate_translation_provider( $is_valid, $input, $ability_name ) {
		if ( is_wp_error( $is_valid ) ) return $is_valid;
		if ( ! in_array( (string) $ability_name, self::$translation_writes, true ) ) return $is_valid;
		$provider = is_array( $input ) && isset( $input['provider'] ) ? sanitize_key( (string) $input['provider'] ) : '';
		if ( ! in_array( $provider, array( 'wpml', 'polylang' ), true ) ) {
			return new WP_Error( 'mad4b_translation_provider_binding_required', 'Translation mutations require an explicit wpml or polylang provider binding.' );
		}
		return true;
	}

	private static function validate_planned_target( array $plan ) {
		$ability_name = isset( $plan['ability'] ) ? (string) $plan['ability'] : '';
		if ( '' === $ability_name ) return true; // Approval Plan schema owns the missing-field error.
		if ( 'mad4b/approval-plan' === $ability_name ) {
			return new WP_Error( 'mad4b_approval_plan_recursive_target_denied', 'Approval Plan cannot target itself.' );
		}
		if ( ! function_exists( 'wp_get_ability' ) ) return new WP_Error( 'mad4b_approval_target_validation_unavailable', 'Target ability validation is unavailable.' );
		$ability = wp_get_ability( $ability_name );
		if ( ! $ability ) return true; // Canonical approval callback owns the unknown-ability error.
		if ( ! method_exists( $ability, 'validate_input' ) ) return new WP_Error( 'mad4b_approval_target_validation_unavailable', 'Target ability does not expose input validation.' );
		$operation_input = isset( $plan['input'] ) && is_array( $plan['input'] ) ? $plan['input'] : array();
		$valid = $ability->validate_input( $operation_input );
		if ( is_wp_error( $valid ) ) {
			return new WP_Error(
				'mad4b_approval_target_input_invalid',
				'Approval Plan target input is invalid for the registered target ability.',
				array(
					'ability' => $ability_name,
					'target_error_code' => $valid->get_error_code(),
					'target_error_message' => $valid->get_error_message(),
				)
			);
		}

		// When JetEngine's own MCP transport is present, the fixed MAD4B wrapper
		// deliberately carries a generic nested `input` object. Bind the approval
		// plan to the exact live native tool name + schema hash and validate that
		// nested input against the discovered JetEngine inputSchema before any
		// Pending Ticket can be created. This performs tools/list only; never call.
		if ( 0 === strpos( $ability_name, 'jetengine/' ) && class_exists( 'MAD4B_SCP_JetEngine_MCP_Client' ) && MAD4B_SCP_JetEngine_MCP_Client::available() ) {
			$expected_name = isset( $operation_input['expected_native_ability'] ) ? (string) $operation_input['expected_native_ability'] : '';
			$expected_hash = isset( $operation_input['expected_schema_sha256'] ) ? strtolower( trim( (string) $operation_input['expected_schema_sha256'] ) ) : '';
			$native_input = isset( $operation_input['input'] ) && is_array( $operation_input['input'] ) ? $operation_input['input'] : array();
			$native_valid = MAD4B_SCP_JetEngine_MCP_Client::validate_tool_input( $expected_name, $native_input, $expected_hash );
			if ( is_wp_error( $native_valid ) ) {
				return new WP_Error(
					'mad4b_approval_target_native_input_invalid',
					'Approval Plan JetEngine native input is not exact-bound to the current MCP tool contract.',
					array(
						'ability' => $ability_name,
						'native_error_code' => $native_valid->get_error_code(),
						'native_error_message' => $native_valid->get_error_message(),
					)
				);
			}
		}
		return true;
	}
}

/**
 * Read-only registry for mutation reversibility semantics that cannot be safely
 * inferred from the presence or absence of a rollback contract.
 */
final class MAD4B_SCP_Mutation_Semantics_Adapter extends MAD4B_SCP_Adapter_Base {
	const CONTRACT = 'mad4b.mutation-semantics.v2';
	private static $hooked = false;

	public static function boot() {
		if ( self::$hooked ) return;
		self::$hooked = true;
		MAD4B_SCP_Approval_Plan_Target_Validation::boot();
		add_action( 'mad4b_scp_register_adapters', array( __CLASS__, 'register_with_registry' ), 8, 1 );
	}

	public static function register_with_registry( $registry ) {
		if ( is_object( $registry ) && method_exists( $registry, 'register' ) ) $registry->register( new self() );
	}

	public function id() { return 'mutation-semantics'; }
	public function label() { return 'Mutation Semantics'; }
	public function is_available() { return true; }
	protected function certified_provider_key() { return 'core'; }
	protected function mutation_requires_certification() { return false; }

	public function ability_names() {
		return array(
			'read' => array( 'mad4b/mutation-semantics' ),
			'content' => array(),
			'admin' => array(),
			'write' => array(),
		);
	}

	public function register_abilities() {
		$this->add_ability(
			'mad4b/mutation-semantics',
			'Mutation Reversibility Semantics',
			'mutation_semantics',
			array( 'MAD4B_SCP_Policy', 'can_read' ),
			$this->schema( array( 'ability' => array( 'type' => 'string', 'maxLength' => 191, 'default' => '' ) ) )
		);
	}

	private function irreversible_map() {
		return array(
			'mad4b/taxonomy-delete-term' => array(
				'reversible' => false,
				'impact' => 'high',
				'reason' => 'wordpress_hard_delete_cannot_restore_same_term_identity',
				'compensation' => 'none',
			),
			'jetengine/create-cpt' => array( 'reversible' => false, 'impact' => 'high', 'reason' => 'provider_native_create_identity_rollback_not_proven', 'compensation' => 'provider_specific_required' ),
			'jetengine/create-taxonomy' => array( 'reversible' => false, 'impact' => 'high', 'reason' => 'provider_native_create_identity_rollback_not_proven', 'compensation' => 'provider_specific_required' ),
			'jetengine/create-meta-box' => array( 'reversible' => false, 'impact' => 'high', 'reason' => 'provider_native_create_identity_rollback_not_proven', 'compensation' => 'provider_specific_required' ),
			'jetengine/create-cct' => array( 'reversible' => false, 'impact' => 'high', 'reason' => 'provider_native_create_identity_rollback_not_proven', 'compensation' => 'provider_specific_required' ),
			'jetengine/create-query' => array( 'reversible' => false, 'impact' => 'high', 'reason' => 'provider_native_create_identity_rollback_not_proven', 'compensation' => 'provider_specific_required' ),
			'jetengine/create-glossary' => array( 'reversible' => false, 'impact' => 'high', 'reason' => 'provider_native_create_identity_rollback_not_proven', 'compensation' => 'provider_specific_required' ),
			'jetengine/create-listing' => array( 'reversible' => false, 'impact' => 'high', 'reason' => 'provider_native_create_identity_rollback_not_proven', 'compensation' => 'provider_specific_required' ),
			'jetengine/manage-modules' => array( 'reversible' => false, 'impact' => 'high', 'reason' => 'provider_native_module_compensation_not_proven', 'compensation' => 'provider_specific_required' ),
			'jetengine/import-configuration' => array( 'reversible' => false, 'impact' => 'high', 'reason' => 'provider_native_import_compensation_not_proven', 'compensation' => 'provider_specific_required' ),
			'mad4b/provider-import-content' => array( 'reversible' => false, 'impact' => 'high', 'reason' => 'generic_provider_import_compensation_not_proven', 'compensation' => 'runtime_blocked_until_proven' ),
		);
	}

	public function mutation_semantics( $input = array() ) {
		$map = $this->irreversible_map();
		$ability = isset( $input['ability'] ) ? (string) $input['ability'] : '';
		if ( '' !== $ability ) {
			return array(
				'contract' => self::CONTRACT,
				'ability' => $ability,
				'declared' => isset( $map[ $ability ] ),
				'semantics' => isset( $map[ $ability ] ) ? $map[ $ability ] : array(),
			);
		}
		return array( 'contract' => self::CONTRACT, 'count' => count( $map ), 'abilities' => $map );
	}
}
