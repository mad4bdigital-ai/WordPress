<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Read-only compiler for declarative governed operation pipelines.
 *
 * The compiler never executes a stage. It resolves stage conditions and binds
 * exact reviewed planner/executor identities so clients can detect drift before
 * crossing any mutation boundary.
 */
final class MAD4B_SCP_Operation_Pipeline {
	const CONTRACT = 'mad4b.operation-pipeline-compile.v1';

	public static function boot() {
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_ability' ), 34 );
	}

	public static function register_ability() {
		if ( ! function_exists( 'wp_register_ability' ) || ( function_exists( 'wp_has_ability' ) && wp_has_ability( 'mad4b/operation-pipeline-compile' ) ) ) return;
		wp_register_ability( 'mad4b/operation-pipeline-compile', array(
			'label' => 'Compile Governed Operation Pipeline',
			'description' => 'Compile a declarative WordPress operation pipeline into exact read/planner/authorization/executor/readback/reconcile stages without executing mutation.',
			'category' => 'mad4b-read',
			'execute_callback' => array( __CLASS__, 'compile' ),
			'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' ),
			'input_schema' => array(
				'type' => 'object',
				'properties' => array(
					'operation' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 96 ),
					'target_kind' => array( 'type' => 'string', 'maxLength' => 64 ),
					'plugin' => array( 'type' => 'string', 'maxLength' => 191 ),
					'provider_id' => array( 'type' => 'string', 'maxLength' => 64 ),
				),
				'required' => array( 'operation' ),
				'additionalProperties' => false,
			),
			'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
			'meta' => array(
				'public' => false,
				'show_in_rest' => false,
				'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'read' ),
				'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
			),
		) );
	}

	private static function condition_value( $path, array $operation, array $input ) {
		$path = strtolower( trim( (string) $path ) );
		if ( 'target_kind' === $path ) {
			return isset( $input['target_kind'] ) && '' !== trim( (string) $input['target_kind'] )
				? sanitize_key( (string) $input['target_kind'] )
				: sanitize_key( isset( $operation['target_kind'] ) ? (string) $operation['target_kind'] : '' );
		}
		$source = null;
		$key = '';
		if ( 0 === strpos( $path, 'operation.' ) ) {
			$source = $operation;
			$key = substr( $path, strlen( 'operation.' ) );
		} elseif ( 0 === strpos( $path, 'input.' ) ) {
			$source = $input;
			$key = substr( $path, strlen( 'input.' ) );
		} else {
			return null;
		}
		if ( ! is_array( $source ) || '' === $key || false !== strpos( $key, '.' ) || ! array_key_exists( $key, $source ) ) return null;
		$value = $source[ $key ];
		if ( is_bool( $value ) ) return $value ? 'true' : 'false';
		if ( is_int( $value ) || is_float( $value ) ) return (string) $value;
		if ( is_string( $value ) ) return sanitize_key( $value );
		return null;
	}

	private static function condition_matches( $condition, array $operation, array $input ) {
		$condition = trim( (string) $condition );
		if ( '' === $condition ) return true;
		$parts = explode( ':', $condition, 2 );
		if ( 2 !== count( $parts ) ) return false;
		$actual = self::condition_value( (string) $parts[0], $operation, $input );
		if ( null === $actual ) return false;
		$expected = sanitize_key( (string) $parts[1] );
		return hash_equals( (string) $expected, (string) $actual );
	}


	public static function compile( $input ) {
		$input = is_array( $input ) ? $input : array();
		if ( ! class_exists( 'MAD4B_SCP_Operation_Registry' ) ) return new WP_Error( 'mad4b_operation_registry_unavailable', 'Operation registry is unavailable.' );

		$discovery = MAD4B_SCP_Operation_Registry::discover( $input );
		if ( is_wp_error( $discovery ) ) return $discovery;
		$operation = isset( $discovery['operation'] ) && is_array( $discovery['operation'] ) ? $discovery['operation'] : array();
		$profile_id = isset( $operation['pipeline_profile'] ) ? sanitize_key( (string) $operation['pipeline_profile'] ) : '';
		$profile = MAD4B_SCP_Operation_Registry::pipeline_profile( $profile_id );
		if ( is_wp_error( $profile ) ) return $profile;

		$compiled = array();
		$active_stage_ids = array();
		foreach ( $profile as $position => $stage ) {
			$condition = isset( $stage['when'] ) ? (string) $stage['when'] : '';
			$enabled = self::condition_matches( $condition, $operation, $input );
			$binding = MAD4B_SCP_Operation_Registry::stage_binding( isset( $stage['id'] ) ? $stage['id'] : '', $operation );
			if ( is_wp_error( $binding ) ) return $binding;
			$ability = isset( $binding['ability'] ) ? (string) $binding['ability'] : '';
			$ability_registered = '' === $ability || ! function_exists( 'wp_has_ability' ) ? null : (bool) wp_has_ability( $ability );
			$row = array(
				'position' => (int) $position,
				'id' => isset( $stage['id'] ) ? sanitize_key( (string) $stage['id'] ) : '',
				'type' => isset( $stage['type'] ) ? sanitize_key( (string) $stage['type'] ) : '',
				'required' => ! empty( $stage['required'] ),
				'condition' => $condition,
				'enabled' => $enabled,
				'binding_type' => isset( $binding['binding_type'] ) ? (string) $binding['binding_type'] : 'unbound',
				'ability' => $ability,
				'ability_registered' => $ability_registered,
			);
			if ( $enabled ) $active_stage_ids[] = $row['id'];
			$compiled[] = $row;
		}

		$missing_required = array();
		foreach ( $compiled as $stage ) {
			if ( empty( $stage['enabled'] ) || empty( $stage['required'] ) ) continue;
			if ( 'ability' === $stage['binding_type'] && false === $stage['ability_registered'] ) $missing_required[] = $stage['ability'];
			if ( 'unbound' === $stage['binding_type'] ) $missing_required[] = 'stage:' . $stage['id'];
		}
		$missing_required = array_values( array_unique( array_filter( $missing_required ) ) );
		sort( $missing_required, SORT_STRING );

		$result = array(
			'contract' => self::CONTRACT,
			'operation_id' => isset( $operation['id'] ) ? (string) $operation['id'] : '',
			'pipeline_profile' => $profile_id,
			'target_kind' => isset( $operation['target_kind'] ) ? (string) $operation['target_kind'] : '',
			'risk' => isset( $operation['risk'] ) ? (string) $operation['risk'] : '',
			'ready' => empty( $missing_required ),
			'state' => empty( $missing_required ) ? 'compiled' : 'required_stage_binding_missing',
			'active_stage_ids' => $active_stage_ids,
			'stages' => $compiled,
			'missing_required_bindings' => $missing_required,
			'planner' => isset( $operation['planner'] ) ? (string) $operation['planner'] : '',
			'executor' => isset( $operation['executor'] ) ? (string) $operation['executor'] : '',
			'generic_mutation_dispatch' => false,
			'arbitrary_stage_execution' => false,
			'mutation_performed' => false,
			'authority_created' => false,
		);
		$encoded = wp_json_encode( $result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$result['pipeline_sha256'] = is_string( $encoded ) ? hash( 'sha256', $encoded ) : '';
		return $result;
	}
}
