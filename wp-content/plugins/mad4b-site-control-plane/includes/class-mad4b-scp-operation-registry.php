<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Provider-neutral operation discovery layer.
 *
 * This class does not execute arbitrary operations. It exposes a deterministic
 * catalog of semantically named, pre-existing governed abilities and returns
 * the exact planner/executor pair a client should use.
 */
final class MAD4B_SCP_Operation_Registry {
	const CONTRACT = 'mad4b.operation-registry.v1';
	const DISCOVER_CONTRACT = 'mad4b.operation-discovery.v1';
	const CATALOG_FILE = 'config/operation-registry.json';

	private static $catalog = null;

	public static function boot() {
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 33 );
	}

	public static function register_abilities() {
		if ( ! function_exists( 'wp_register_ability' ) ) return;
		if ( ! function_exists( 'wp_has_ability' ) || ! wp_has_ability( 'mad4b/operation-registry-status' ) ) {
			wp_register_ability( 'mad4b/operation-registry-status', array(
				'label' => 'MAD4B Operation Registry Status',
				'description' => 'Inspect the provider-neutral governed WordPress operation catalog.',
				'category' => 'mad4b-read',
				'execute_callback' => array( __CLASS__, 'status' ),
				'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' ),
				'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
				'meta' => array(
					'public' => false,
					'show_in_rest' => false,
					'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'read' ),
					'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
				),
			) );
		}
		if ( ! function_exists( 'wp_has_ability' ) || ! wp_has_ability( 'mad4b/wordpress-operation-discover' ) ) {
			wp_register_ability( 'mad4b/wordpress-operation-discover', array(
				'label' => 'Discover Governed WordPress Operation',
				'description' => 'Resolve a requested semantic operation to an exact governed planner/executor pair without creating authority or executing mutation.',
				'category' => 'mad4b-read',
				'execute_callback' => array( __CLASS__, 'discover' ),
				'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' ),
				'input_schema' => array(
					'type' => 'object',
					'properties' => array(
						'operation' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 64 ),
						'target_kind' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 64 ),
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
	}

	private static function catalog() {
		if ( null !== self::$catalog ) return self::$catalog;
		$path = MAD4B_SCP_DIR . self::CATALOG_FILE;
		if ( ! is_file( $path ) || ! is_readable( $path ) || is_link( $path ) ) {
			self::$catalog = new WP_Error( 'mad4b_operation_registry_missing', 'MAD4B operation registry catalog is unavailable.' );
			return self::$catalog;
		}
		$raw = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$data = is_string( $raw ) ? json_decode( $raw, true ) : null;
		if ( ! is_array( $data ) || self::CONTRACT !== ( isset( $data['contract'] ) ? (string) $data['contract'] : '' ) || empty( $data['operations'] ) || ! is_array( $data['operations'] ) ) {
			self::$catalog = new WP_Error( 'mad4b_operation_registry_invalid', 'MAD4B operation registry catalog contract is invalid.' );
			return self::$catalog;
		}
		if ( 1 !== ( isset( $data['version'] ) ? (int) $data['version'] : 0 )
			|| 'deny' !== ( isset( $data['default_mutation_policy'] ) ? (string) $data['default_mutation_policy'] : '' )
			|| false !== ( isset( $data['generic_shell'] ) ? $data['generic_shell'] : null )
			|| false !== ( isset( $data['arbitrary_operation_ids'] ) ? $data['arbitrary_operation_ids'] : null ) ) {
			self::$catalog = new WP_Error( 'mad4b_operation_registry_policy_invalid', 'MAD4B operation registry safety policy is invalid.' );
			return self::$catalog;
		}
		$ids = array();
		foreach ( $data['operations'] as $row ) {
			if ( ! is_array( $row ) || empty( $row['id'] ) || empty( $row['planner'] ) || empty( $row['executor'] ) ) {
				self::$catalog = new WP_Error( 'mad4b_operation_registry_item_invalid', 'MAD4B operation registry contains an incomplete operation.' );
				return self::$catalog;
			}
			$id = strtolower( trim( (string) $row['id'] ) );
			$planner = strtolower( trim( (string) $row['planner'] ) );
			$executor = strtolower( trim( (string) $row['executor'] ) );
			if ( ! preg_match( '/^[a-z0-9][a-z0-9._-]{0,95}$/', $id ) ) {
				self::$catalog = new WP_Error( 'mad4b_operation_registry_id_invalid', 'MAD4B operation registry contains an invalid operation id.' );
				return self::$catalog;
			}
			if ( ! preg_match( '#^[a-z0-9][a-z0-9._-]*/[a-z0-9][a-z0-9._-]*$#', $planner ) ) {
				self::$catalog = new WP_Error( 'mad4b_operation_registry_planner_invalid', 'MAD4B operation registry contains an invalid planner ability name.' );
				return self::$catalog;
			}
			if ( 'exact_executor_from_plan' !== $executor && ! preg_match( '#^[a-z0-9][a-z0-9._-]*/[a-z0-9][a-z0-9._-]*$#', $executor ) ) {
				self::$catalog = new WP_Error( 'mad4b_operation_registry_executor_invalid', 'MAD4B operation registry contains an invalid executor ability name.' );
				return self::$catalog;
			}
			if ( isset( $row['required_runtime'] ) && ! is_bool( $row['required_runtime'] ) ) {
				self::$catalog = new WP_Error( 'mad4b_operation_registry_required_runtime_invalid', 'MAD4B operation registry required_runtime must be boolean.' );
				return self::$catalog;
			}
			if ( isset( $ids[ $id ] ) ) {
				self::$catalog = new WP_Error( 'mad4b_operation_registry_duplicate', 'MAD4B operation registry contains duplicate operation ids.' );
				return self::$catalog;
			}
			$ids[ $id ] = true;
		}
		$aliases = isset( $data['aliases'] ) && is_array( $data['aliases'] ) ? $data['aliases'] : array();
		foreach ( $aliases as $alias => $target_id ) {
			$alias = (string) $alias;
			$target_id = (string) $target_id;
			if ( ! preg_match( '/^[a-z0-9][a-z0-9._-]{0,63}$/', $alias ) || ! isset( $ids[ $target_id ] ) ) {
				self::$catalog = new WP_Error( 'mad4b_operation_registry_alias_invalid', 'MAD4B operation registry contains an invalid alias or target.' );
				return self::$catalog;
			}
		}
		$stage_bindings = isset( $data['stage_bindings'] ) && is_array( $data['stage_bindings'] ) ? $data['stage_bindings'] : array();
		if ( empty( $stage_bindings ) ) {
			self::$catalog = new WP_Error( 'mad4b_operation_registry_stage_bindings_missing', 'MAD4B operation registry stage bindings are missing.' );
			return self::$catalog;
		}
		$allowed_binding_types = array( 'ability', 'operation_planner', 'operation_executor', 'policy_boundary', 'executor_owned_verification' );
		foreach ( $stage_bindings as $stage_id => $binding ) {
			$stage_id = (string) $stage_id;
			if ( sanitize_key( $stage_id ) !== $stage_id || ! is_array( $binding ) ) {
				self::$catalog = new WP_Error( 'mad4b_operation_registry_stage_binding_invalid', 'MAD4B operation registry contains an invalid stage binding.' );
				return self::$catalog;
			}
			$binding_type = isset( $binding['binding_type'] ) ? sanitize_key( (string) $binding['binding_type'] ) : '';
			if ( ! in_array( $binding_type, $allowed_binding_types, true ) ) {
				self::$catalog = new WP_Error( 'mad4b_operation_registry_stage_binding_type_invalid', 'MAD4B operation registry contains an unsupported stage binding type.' );
				return self::$catalog;
			}
			if ( 'ability' === $binding_type ) {
				$ability = isset( $binding['ability'] ) ? strtolower( trim( (string) $binding['ability'] ) ) : '';
				if ( ! preg_match( '#^[a-z0-9][a-z0-9._-]*/[a-z0-9][a-z0-9._-]*$#', $ability ) ) {
					self::$catalog = new WP_Error( 'mad4b_operation_registry_stage_binding_ability_invalid', 'MAD4B operation registry stage binding references an invalid Ability.' );
					return self::$catalog;
				}
			}
		}

		$profiles = isset( $data['pipeline_profiles'] ) && is_array( $data['pipeline_profiles'] ) ? $data['pipeline_profiles'] : array();
		if ( empty( $profiles ) ) {
			self::$catalog = new WP_Error( 'mad4b_operation_registry_pipeline_profiles_missing', 'MAD4B operation registry pipeline profiles are missing.' );
			return self::$catalog;
		}
		$allowed_stage_types = array( 'read_check', 'planner', 'authorization', 'executor', 'verification', 'reconcile' );
		foreach ( $profiles as $profile_id => $stages ) {
			if ( sanitize_key( (string) $profile_id ) !== (string) $profile_id || ! is_array( $stages ) || empty( $stages ) ) {
				self::$catalog = new WP_Error( 'mad4b_operation_registry_pipeline_profile_invalid', 'MAD4B operation registry contains an invalid pipeline profile.' );
				return self::$catalog;
			}
			$stage_ids = array();
			$type_positions = array();
			foreach ( array_values( $stages ) as $position => $stage ) {
				if ( ! is_array( $stage ) || empty( $stage['id'] ) || empty( $stage['type'] ) ) {
					self::$catalog = new WP_Error( 'mad4b_operation_registry_pipeline_stage_invalid', 'MAD4B operation registry contains an incomplete pipeline stage.' );
					return self::$catalog;
				}
				$stage_id = sanitize_key( (string) $stage['id'] );
				$type = sanitize_key( (string) $stage['type'] );
				if ( $stage_id !== (string) $stage['id'] || isset( $stage_ids[ $stage_id ] ) || ! in_array( $type, $allowed_stage_types, true ) || ! isset( $stage_bindings[ $stage_id ] ) ) {
					self::$catalog = new WP_Error( 'mad4b_operation_registry_pipeline_stage_invalid', 'MAD4B operation registry contains an invalid or duplicate pipeline stage.' );
					return self::$catalog;
				}
				if ( isset( $stage['required'] ) && ! is_bool( $stage['required'] ) ) {
					self::$catalog = new WP_Error( 'mad4b_operation_registry_pipeline_stage_required_invalid', 'Pipeline stage required must be boolean.' );
					return self::$catalog;
				}
				if ( isset( $stage['when'] ) ) {
					$condition = strtolower( trim( (string) $stage['when'] ) );
					if ( ! preg_match( '/^(?:target_kind|operation\.[a-z0-9_-]+|input\.[a-z0-9_-]+):[a-z0-9_.-]+$/', $condition ) ) {
						self::$catalog = new WP_Error( 'mad4b_operation_registry_pipeline_condition_invalid', 'Pipeline stage condition syntax or source is invalid.' );
						return self::$catalog;
					}
				}
				$stage_ids[ $stage_id ] = true;
				if ( ! isset( $type_positions[ $type ] ) ) $type_positions[ $type ] = array();
				$type_positions[ $type ][] = (int) $position;
			}
			foreach ( array( 'planner', 'authorization', 'executor', 'verification' ) as $required_type ) {
				if ( empty( $type_positions[ $required_type ] ) ) {
					self::$catalog = new WP_Error( 'mad4b_operation_registry_pipeline_required_stage_missing', 'Pipeline profile is missing a required governed mutation stage.' );
					return self::$catalog;
				}
			}
			$planner_pos = min( $type_positions['planner'] );
			$auth_pos = min( $type_positions['authorization'] );
			$executor_pos = min( $type_positions['executor'] );
			$verification_pos = min( $type_positions['verification'] );
			if ( ! ( $planner_pos < $auth_pos && $auth_pos < $executor_pos && $executor_pos < $verification_pos ) ) {
				self::$catalog = new WP_Error( 'mad4b_operation_registry_pipeline_order_invalid', 'Pipeline profile violates planner/authorization/executor/verification ordering.' );
				return self::$catalog;
			}
		}
		foreach ( $data['operations'] as $row ) {
			$profile_id = isset( $row['pipeline_profile'] ) ? sanitize_key( (string) $row['pipeline_profile'] ) : '';
			if ( '' === $profile_id || ! isset( $profiles[ $profile_id ] ) ) {
				self::$catalog = new WP_Error( 'mad4b_operation_registry_pipeline_binding_invalid', 'Operation is not bound to a valid pipeline profile.' );
				return self::$catalog;
			}
		}

		$projection = isset( $data['read_projection'] ) && is_array( $data['read_projection'] ) ? $data['read_projection'] : array();
		foreach ( array( 'direct', 'catalog' ) as $surface ) {
			if ( ! isset( $projection[ $surface ] ) || ! is_array( $projection[ $surface ] ) ) {
				self::$catalog = new WP_Error( 'mad4b_operation_registry_projection_invalid', 'MAD4B operation registry read projection is incomplete.' );
				return self::$catalog;
			}
			$seen = array();
			foreach ( $projection[ $surface ] as $ability ) {
				$ability = (string) $ability;
				if ( ! preg_match( '#^[a-z0-9][a-z0-9._-]*/[a-z0-9][a-z0-9._-]*$#', $ability ) || isset( $seen[ $ability ] ) ) {
					self::$catalog = new WP_Error( 'mad4b_operation_registry_projection_invalid', 'MAD4B operation registry read projection contains an invalid or duplicate Ability.' );
					return self::$catalog;
				}
				$seen[ $ability ] = true;
			}
		}
		if ( array_diff( $projection['direct'], $projection['catalog'] ) ) {
			self::$catalog = new WP_Error( 'mad4b_operation_registry_projection_invalid', 'Direct WordPress operation tools must be a subset of the full read catalog.' );
			return self::$catalog;
		}
		self::$catalog = $data;
		return self::$catalog;
	}

	public static function read_projection( $surface = 'catalog' ) {
		$surface = sanitize_key( (string) $surface );
		if ( ! in_array( $surface, array( 'direct', 'catalog' ), true ) ) return array();
		$catalog = self::catalog();
		if ( is_wp_error( $catalog ) ) return array();
		$projection = isset( $catalog['read_projection'][ $surface ] ) && is_array( $catalog['read_projection'][ $surface ] )
			? $catalog['read_projection'][ $surface ]
			: array();
		return array_values( array_unique( array_map( 'strval', $projection ) ) );
	}

	public static function aliases() {
		$catalog = self::catalog();
		return is_wp_error( $catalog ) || empty( $catalog['aliases'] ) || ! is_array( $catalog['aliases'] ) ? array() : $catalog['aliases'];
	}

	public static function operation( $operation_id ) {
		$operation_id = strtolower( trim( (string) $operation_id ) );
		$catalog = self::catalog();
		if ( is_wp_error( $catalog ) ) return $catalog;
		foreach ( $catalog['operations'] as $row ) {
			if ( isset( $row['id'] ) && $operation_id === strtolower( trim( (string) $row['id'] ) ) ) return $row;
		}
		return new WP_Error( 'mad4b_operation_not_registered', 'Requested operation is not present in the governed operation registry.' );
	}

	public static function stage_binding( $stage_id, array $operation = array() ) {
		$stage_id = sanitize_key( (string) $stage_id );
		$catalog = self::catalog();
		if ( is_wp_error( $catalog ) ) return $catalog;
		if ( '' === $stage_id || ! isset( $catalog['stage_bindings'][ $stage_id ] ) || ! is_array( $catalog['stage_bindings'][ $stage_id ] ) ) {
			return new WP_Error( 'mad4b_operation_stage_binding_not_registered', 'Requested operation stage binding is not registered.' );
		}
		$binding = $catalog['stage_bindings'][ $stage_id ];
		$type = isset( $binding['binding_type'] ) ? sanitize_key( (string) $binding['binding_type'] ) : '';
		if ( 'operation_planner' === $type ) return array( 'binding_type' => 'ability', 'ability' => isset( $operation['planner'] ) ? (string) $operation['planner'] : '' );
		if ( 'operation_executor' === $type ) {
			$executor = isset( $operation['executor'] ) ? (string) $operation['executor'] : '';
			return array(
				'binding_type' => 'exact_executor_from_plan' === $executor ? 'plan_bound_executor' : 'ability',
				'ability' => 'exact_executor_from_plan' === $executor ? '' : $executor,
			);
		}
		return array(
			'binding_type' => $type,
			'ability' => isset( $binding['ability'] ) ? (string) $binding['ability'] : '',
		);
	}

	public static function pipeline_profile( $profile_id ) {
		$profile_id = sanitize_key( (string) $profile_id );
		$catalog = self::catalog();
		if ( is_wp_error( $catalog ) ) return $catalog;
		if ( '' === $profile_id || ! isset( $catalog['pipeline_profiles'][ $profile_id ] ) || ! is_array( $catalog['pipeline_profiles'][ $profile_id ] ) ) {
			return new WP_Error( 'mad4b_operation_pipeline_profile_not_registered', 'Requested operation pipeline profile is not registered.' );
		}
		return array_values( $catalog['pipeline_profiles'][ $profile_id ] );
	}

	public static function status( $input = null ) {
		$catalog = self::catalog();
		if ( is_wp_error( $catalog ) ) return array(
			'contract' => self::CONTRACT,
			'ready' => false,
			'state' => 'catalog_invalid',
			'error_code' => $catalog->get_error_code(),
			'generic_shell' => false,
			'arbitrary_operation_ids' => false,
			'authority_created' => false,
		);
		$items = array();
		$missing = array();
		$optional_unavailable = array();
		$projection_missing = array();
		foreach ( $catalog['operations'] as $row ) {
			$planner = (string) $row['planner'];
			$executor = (string) $row['executor'];
			$planner_registered = function_exists( 'wp_has_ability' ) ? (bool) wp_has_ability( $planner ) : null;
			$executor_registered = 'exact_executor_from_plan' === $executor ? true : ( function_exists( 'wp_has_ability' ) ? (bool) wp_has_ability( $executor ) : null );
			$required_runtime = ! array_key_exists( 'required_runtime', $row ) || true === $row['required_runtime'];
			if ( false === $planner_registered || false === $executor_registered ) {
				$target = isset( $row['id'] ) ? (string) $row['id'] : '';
				if ( $required_runtime ) {
					if ( false === $planner_registered ) $missing[] = $planner;
					if ( false === $executor_registered ) $missing[] = $executor;
				} elseif ( '' !== $target ) {
					$optional_unavailable[] = $target;
				}
			}
			$items[] = array_merge( $row, array(
				'planner_registered' => $planner_registered,
				'executor_registered' => $executor_registered,
			) );
		}
		$missing = array_values( array_unique( $missing ) );
		sort( $missing, SORT_STRING );
		if ( function_exists( 'wp_has_ability' ) ) {
			foreach ( self::read_projection( 'catalog' ) as $ability_name ) {
				if ( ! wp_has_ability( $ability_name ) ) $projection_missing[] = $ability_name;
			}
		}
		$projection_missing = array_values( array_unique( $projection_missing ) );
		sort( $projection_missing, SORT_STRING );
		$ready = empty( $missing ) && empty( $projection_missing );
		$encoded_catalog = wp_json_encode( $catalog, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return array(
			'contract' => self::CONTRACT,
			'ready' => $ready,
			'state' => $ready ? 'ready' : ( ! empty( $missing ) ? 'registered_ability_gap' : 'read_projection_gap' ),
			'default_mutation_policy' => 'deny',
			'generic_shell' => false,
			'arbitrary_operation_ids' => false,
			'authority_created' => false,
			'operations' => $items,
			'missing_registered_abilities' => $missing,
			'projection_missing_registered_abilities' => $projection_missing,
			'optional_unavailable_operations' => array_values( array_unique( $optional_unavailable ) ),
			'catalog_sha256' => is_string( $encoded_catalog ) ? hash( 'sha256', $encoded_catalog ) : '',
			'read_projection' => array(
				'direct' => self::read_projection( 'direct' ),
				'catalog' => self::read_projection( 'catalog' ),
			),
			'pipeline_profiles' => array_keys( isset( $catalog['pipeline_profiles'] ) && is_array( $catalog['pipeline_profiles'] ) ? $catalog['pipeline_profiles'] : array() ),
			'stage_bindings' => array_keys( isset( $catalog['stage_bindings'] ) && is_array( $catalog['stage_bindings'] ) ? $catalog['stage_bindings'] : array() ),
			'count' => count( $items ),
		);
	}

	public static function discover( $input ) {
		$input = is_array( $input ) ? $input : array();
		$requested_raw = strtolower( trim( isset( $input['operation'] ) ? (string) $input['operation'] : '' ) );
		$requested = preg_replace( '/[^a-z0-9._-]/', '', $requested_raw );
		$target_kind = sanitize_key( isset( $input['target_kind'] ) ? (string) $input['target_kind'] : '' );
		if ( '' === $requested ) return new WP_Error( 'mad4b_operation_required', 'A semantic operation or exact registered operation id is required.' );
		$catalog = self::catalog();
		if ( is_wp_error( $catalog ) ) return $catalog;

		$aliases = self::aliases();
		$desired_id = isset( $aliases[ $requested ] ) ? $aliases[ $requested ] : $requested;

		$exact = array();
		foreach ( $catalog['operations'] as $row ) {
			$id = isset( $row['id'] ) ? strtolower( trim( (string) $row['id'] ) ) : '';
			$kind = isset( $row['target_kind'] ) ? sanitize_key( (string) $row['target_kind'] ) : '';
			if ( $id !== $desired_id ) continue;
			if ( '' !== $target_kind && '' !== $kind && $target_kind !== $kind ) continue;
			$exact[] = $row;
		}
		$matches = $exact;
		if ( empty( $matches ) ) {
			foreach ( $catalog['operations'] as $row ) {
				$supports = isset( $row['supports'] ) && is_array( $row['supports'] ) ? array_map( 'sanitize_key', $row['supports'] ) : array();
				$kind = isset( $row['target_kind'] ) ? sanitize_key( (string) $row['target_kind'] ) : '';
				if ( ! in_array( sanitize_key( $requested ), $supports, true ) ) continue;
				if ( '' !== $target_kind && '' !== $kind && $target_kind !== $kind ) continue;
				$matches[] = $row;
			}
		}
		if ( 1 !== count( $matches ) ) {
			return new WP_Error(
				empty( $matches ) ? 'mad4b_operation_not_registered' : 'mad4b_operation_ambiguous',
				empty( $matches ) ? 'Requested operation is not present in the governed operation registry.' : 'Requested operation maps to more than one governed operation; use an exact registered operation id.',
				array( 'requested_operation' => $requested, 'target_kind' => $target_kind, 'candidate_count' => count( $matches ) )
			);
		}
		$row = $matches[0];
		$planner = (string) $row['planner'];
		$executor = (string) $row['executor'];
		if ( function_exists( 'wp_has_ability' ) && ! wp_has_ability( $planner ) ) return new WP_Error( 'mad4b_operation_planner_unregistered', 'Registered operation planner is unavailable in the current runtime.', array( 'planner' => $planner ) );
		if ( 'exact_executor_from_plan' !== $executor && function_exists( 'wp_has_ability' ) && ! wp_has_ability( $executor ) ) return new WP_Error( 'mad4b_operation_executor_unregistered', 'Registered operation executor is unavailable in the current runtime.', array( 'executor' => $executor ) );

		$impact = null;
		if ( ! empty( $row['dependency_impact'] ) && class_exists( 'MAD4B_SCP_Dependency_Impact_Graph' ) ) {
			$impact = MAD4B_SCP_Dependency_Impact_Graph::inspect( array(
				'plugin' => isset( $input['plugin'] ) ? (string) $input['plugin'] : '',
				'provider_id' => isset( $input['provider_id'] ) ? (string) $input['provider_id'] : '',
			) );
		}
		return array(
			'contract' => self::DISCOVER_CONTRACT,
			'operation' => $row,
			'requested_operation' => $requested,
			'planner' => $planner,
			'executor' => $executor,
			'dependency_impact' => is_wp_error( $impact ) ? array( 'available' => false, 'error_code' => $impact->get_error_code() ) : $impact,
			'mutation_performed' => false,
			'authority_created' => false,
			'next_action' => 'execute_planner_then_bind_exact_plan_digest',
		);
	}
}
