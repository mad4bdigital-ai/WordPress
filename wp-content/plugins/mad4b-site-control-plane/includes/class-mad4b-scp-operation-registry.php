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
		self::$catalog = $data;
		return self::$catalog;
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
		return array(
			'contract' => self::CONTRACT,
			'ready' => empty( $missing ),
			'state' => empty( $missing ) ? 'ready' : 'registered_ability_gap',
			'default_mutation_policy' => 'deny',
			'generic_shell' => false,
			'arbitrary_operation_ids' => false,
			'authority_created' => false,
			'operations' => $items,
			'missing_registered_abilities' => $missing,
			'optional_unavailable_operations' => array_values( array_unique( $optional_unavailable ) ),
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

		$aliases = array(
			'update' => 'wordpress.plugin.transaction',
			'install' => 'wordpress.plugin.transaction',
			'replace' => 'wordpress.plugin.transaction',
			'activate' => 'wordpress.plugin.transaction',
			'deactivate' => 'wordpress.plugin.transaction',
			'self-update' => 'wordpress.control-plane.self-update',
			'reconcile-skills' => 'wordpress.skills.reconcile',
			'reconcile-provider' => 'wordpress.provider.recertify',
			'recertify-provider' => 'wordpress.provider.recertify',
			'import' => 'wordpress.content.import',
			'export' => 'wordpress.content.export',
		);
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
