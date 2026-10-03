<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Request-local idempotence fence for governed mutation execution.
 *
 * The fence never grants mutation authority and never changes approval state.
 * It only prevents the exact same ticket/target from entering the execution
 * callback more than once inside one logical request. Independent requests
 * continue through the canonical approval replay checks.
 */
final class MAD4B_SCP_Execution_Fence {
	const CONTRACT = 'mad4b.same-request-execution-fence.v1';
	const FINAL_ADMISSION_CONTRACT = 'mad4b.final-execution-admission.v1';
	const PROJECTED_CALL_SEAL_CONTRACT = 'mad4b.projected-call-seal.v1';
	const CHILD_OPERATION_CONTRACT = 'mad4b.governed-child-operation.v1';

	private static $booted = false;
	private static $entries = array();
	private static $trusted_final_boundaries = array();
	private static $projected_call_seals = array();
	private static $projected_call_requirements = array();
	private static $execution_stack = array();
	private static $child_permit = array();

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;
		add_filter( 'wp_register_ability_args', array( __CLASS__, 'wrap_governed_write' ), 200, 2 );
		add_filter( 'wp_register_ability_args', array( __CLASS__, 'wrap_final_execution_admission' ), PHP_INT_MAX, 2 );
	}

	public static function wrap_governed_write( $args, $name ) {
		if ( ! is_array( $args ) || ! isset( $args['execute_callback'] ) || ! is_callable( $args['execute_callback'] ) ) return $args;
		$meta = isset( $args['meta'] ) && is_array( $args['meta'] ) ? $args['meta'] : array();
		$annotations = isset( $meta['annotations'] ) && is_array( $meta['annotations'] ) ? $meta['annotations'] : array();
		$mcp = isset( $meta['mcp'] ) && is_array( $meta['mcp'] ) ? $meta['mcp'] : array();
		if ( ! array_key_exists( 'readonly', $annotations ) || false !== $annotations['readonly'] ) return $args;
		if ( empty( $mcp['mad4b_execution_boundary'] ) || ! empty( $mcp['mad4b_same_request_execution_fence'] ) ) return $args;

		$declared_server = self::declared_server( $args );
		$original_execute = $args['execute_callback'];
		$original_permission = isset( $args['permission_callback'] ) && is_callable( $args['permission_callback'] ) ? $args['permission_callback'] : null;

		if ( $original_permission ) {
			$args['permission_callback'] = static function ( $input = null ) use ( $original_permission, $name, $declared_server ) {
				$key = MAD4B_SCP_Execution_Fence::key_for( $name, $declared_server, $input );
				if ( '' !== $key && isset( MAD4B_SCP_Execution_Fence::$entries[ $key ] ) ) {
					$entry = MAD4B_SCP_Execution_Fence::$entries[ $key ];
					if ( 'in_progress' === $entry['state'] ) {
						return new WP_Error( 'mad4b_execution_reentry_denied', 'The exact governed mutation is already executing in this request.' );
					}
					if ( 'completed' === $entry['state'] ) return true;
					if ( 'permission_denied' === $entry['state'] && isset( $entry['result'] ) && is_wp_error( $entry['result'] ) ) return $entry['result'];
				}

				$result = call_user_func( $original_permission, $input );
				// The Authorization wrapper inside this fence records the canonical
				// permission-time replay denial. Cache only that terminal replay result
				// for this exact logical request/ticket/target so repeated permission
				// evaluation cannot append a second audit event. Independent requests
				// have a different request-local key and still hit canonical replay
				// authorization and emit their own evidence.
				if ( '' !== $key && is_wp_error( $result ) && 'mad4b_approval_replay_denied' === (string) $result->get_error_code() ) {
					MAD4B_SCP_Execution_Fence::$entries[ $key ] = array( 'state' => 'permission_denied', 'result' => $result );
				}
				return $result;
			};
		}

		$args['execute_callback'] = static function ( $input = null ) use ( $original_execute, $name, $declared_server ) {
			$key = MAD4B_SCP_Execution_Fence::key_for( $name, $declared_server, $input );
			if ( '' === $key ) return call_user_func( $original_execute, $input );

			if ( isset( MAD4B_SCP_Execution_Fence::$entries[ $key ] ) ) {
				$entry = MAD4B_SCP_Execution_Fence::$entries[ $key ];
				if ( 'in_progress' === $entry['state'] ) {
					return new WP_Error( 'mad4b_execution_reentry_denied', 'The exact governed mutation is already executing in this request.' );
				}
				if ( 'completed' === $entry['state'] && array_key_exists( 'result', $entry ) ) return $entry['result'];
				if ( 'permission_denied' === $entry['state'] && isset( $entry['result'] ) && is_wp_error( $entry['result'] ) ) return $entry['result'];
			}

			MAD4B_SCP_Execution_Fence::$entries[ $key ] = array( 'state' => 'in_progress' );
			try {
				$result = call_user_func( $original_execute, $input );
			} catch ( Throwable $throwable ) {
				MAD4B_SCP_Execution_Fence::$entries[ $key ] = array(
					'state' => 'completed',
					'result' => new WP_Error( 'mad4b_execution_exception', 'Governed mutation threw before a reusable result was available.' ),
				);
				throw $throwable;
			}
			MAD4B_SCP_Execution_Fence::$entries[ $key ] = array( 'state' => 'completed', 'result' => $result );
			return $result;
		};

		if ( class_exists( 'MAD4B_SCP_Authorization' )
			&& ! MAD4B_SCP_Authorization::propagate_trusted_execution_boundary( $name, $args['execute_callback'], $original_execute ) ) {
			$args['execute_callback'] = static function () {
				return new WP_Error( 'mad4b_execution_boundary_provenance_lost', 'Same-request execution fence could not preserve the reviewed authorization boundary provenance.' );
			};
			if ( ! isset( $args['meta'] ) || ! is_array( $args['meta'] ) ) $args['meta'] = array();
			if ( ! isset( $args['meta']['mcp'] ) || ! is_array( $args['meta']['mcp'] ) ) $args['meta']['mcp'] = array();
			$args['meta']['mcp']['mad4b_execution_boundary_provenance_lost'] = true;
		}
		if ( ! isset( $args['meta']['mcp'] ) || ! is_array( $args['meta']['mcp'] ) ) $args['meta']['mcp'] = array();
		$args['meta']['mcp']['mad4b_same_request_execution_fence'] = self::CONTRACT;
		return $args;
	}

	public static function wrap_final_execution_admission( $args, $name ) {
		if ( ! is_array( $args ) || ! isset( $args['execute_callback'] ) || ! is_callable( $args['execute_callback'] ) ) return $args;
		if ( isset( $args['meta']['mcp']['mad4b_final_execution_admission'] )
			&& self::FINAL_ADMISSION_CONTRACT === (string) $args['meta']['mcp']['mad4b_final_execution_admission'] ) return $args;

		$meta = isset( $args['meta'] ) && is_array( $args['meta'] ) ? $args['meta'] : array();
		$annotations = isset( $meta['annotations'] ) && is_array( $meta['annotations'] ) ? $meta['annotations'] : array();
		$mcp = isset( $meta['mcp'] ) && is_array( $meta['mcp'] ) ? $meta['mcp'] : array();
		$mutation = array_key_exists( 'readonly', $annotations ) && false === $annotations['readonly'];
		$original = $args['execute_callback'];

		$outer = static function ( $input = null ) use ( $original, $name, $mutation ) {
			$governed_child = MAD4B_SCP_Execution_Fence::governed_child_permit_matches( $name, $input );
			if ( MAD4B_SCP_Execution_Fence::projected_call_requires_seal( $name ) && ! $governed_child ) {
				$seal = MAD4B_SCP_Execution_Fence::consume_projected_call( $name, $input );
				if ( is_wp_error( $seal ) ) return $seal;
			}
			$frame = MAD4B_SCP_Execution_Fence::enter_execution_frame( $name, $input, $mutation );
			if ( is_wp_error( $frame ) ) return $frame;
			try {
				return call_user_func( $original, $input );
			} finally {
				MAD4B_SCP_Execution_Fence::leave_execution_frame( $frame );
			}
		};

		if ( $mutation && ! empty( $mcp['mad4b_execution_boundary'] ) && class_exists( 'MAD4B_SCP_Authorization' ) ) {
			if ( ! MAD4B_SCP_Authorization::propagate_trusted_execution_boundary( $name, $outer, $original ) ) {
				$outer = static function () {
					return new WP_Error( 'mad4b_final_execution_boundary_provenance_lost', 'Final execution admission could not preserve the reviewed authorization boundary provenance.' );
				};
				if ( ! isset( $args['meta'] ) || ! is_array( $args['meta'] ) ) $args['meta'] = array();
				if ( ! isset( $args['meta']['mcp'] ) || ! is_array( $args['meta']['mcp'] ) ) $args['meta']['mcp'] = array();
				$args['meta']['mcp']['mad4b_final_execution_boundary_provenance_lost'] = true;
			}
		}

		$args['execute_callback'] = $outer;
		if ( ! isset( self::$trusted_final_boundaries[ $name ] ) ) self::$trusted_final_boundaries[ $name ] = array();
		if ( ! in_array( $outer, self::$trusted_final_boundaries[ $name ], true ) ) self::$trusted_final_boundaries[ $name ][] = $outer;
		if ( ! isset( $args['meta'] ) || ! is_array( $args['meta'] ) ) $args['meta'] = array();
		if ( ! isset( $args['meta']['mcp'] ) || ! is_array( $args['meta']['mcp'] ) ) $args['meta']['mcp'] = array();
		$args['meta']['mcp']['mad4b_final_execution_admission'] = self::FINAL_ADMISSION_CONTRACT;
		$args['meta']['mcp']['mad4b_recursive_child_operation_contract'] = self::CHILD_OPERATION_CONTRACT;
		return $args;
	}

	public static function final_execution_wrapper_verified( $ability_name ) {
		$ability_name = (string) $ability_name;
		if ( '' === $ability_name || ! function_exists( 'wp_get_ability' ) ) return false;
		$ability = wp_get_ability( $ability_name );
		if ( ! is_object( $ability ) || empty( self::$trusted_final_boundaries[ $ability_name ] ) ) return false;
		try {
			$property = ( new ReflectionObject( $ability ) )->getProperty( 'execute_callback' );
			$property->setAccessible( true );
			$callback = $property->getValue( $ability );
			return $callback instanceof Closure && in_array( $callback, self::$trusted_final_boundaries[ $ability_name ], true );
		} catch ( Throwable $error ) {
			return false;
		}
	}

	public static function require_projected_call_seal( $ability_name ) {
		$ability_name = trim( (string) $ability_name );
		if ( '' === $ability_name ) return new WP_Error( 'mad4b_projection_execution_requirement_identity_invalid', 'Projected execution requirement needs an exact Ability identity.' );
		self::$projected_call_requirements[ $ability_name ] = array(
			'contract' => self::PROJECTED_CALL_SEAL_CONTRACT,
			'required' => true,
		);
		return true;
	}

	public static function projected_call_requirement_pending( $ability_name ) {
		$ability_name = (string) $ability_name;
		return '' !== $ability_name && isset( self::$projected_call_requirements[ $ability_name ] );
	}

	public static function seal_projected_call( $ability_name, $input, $tool, $server ) {
		$ability_name = (string) $ability_name;
		if ( '' === $ability_name ) return new WP_Error( 'mad4b_projection_execution_seal_identity_invalid', 'Projected execution seal requires an exact Ability identity.' );
		if ( isset( self::$projected_call_seals[ $ability_name ] ) ) {
			return new WP_Error( 'mad4b_projection_execution_seal_pending', 'A projected execution seal is already pending for this Ability in the current request.' );
		}
		$identity = self::projected_execution_identity( $ability_name );
		if ( is_wp_error( $identity ) ) return $identity;
		$input_sha = self::projected_call_input_sha256( $input );
		$identity_sha = self::digest( self::PROJECTED_CALL_SEAL_CONTRACT, $identity );
		if ( '' === $input_sha || '' === $identity_sha ) return new WP_Error( 'mad4b_projection_execution_seal_digest_failed', 'Projected execution seal could not bind the exact call identity.' );
		self::$projected_call_seals[ $ability_name ] = array(
			'contract' => self::PROJECTED_CALL_SEAL_CONTRACT,
			'input_sha256' => $input_sha,
			'identity_sha256' => $identity_sha,
			'tool' => $tool,
			'server' => $server,
		);
		return true;
	}

	public static function consume_projected_call( $ability_name, $input ) {
		$ability_name = (string) $ability_name;
		$required = isset( self::$projected_call_requirements[ $ability_name ] );
		unset( self::$projected_call_requirements[ $ability_name ] );
		if ( ! isset( self::$projected_call_seals[ $ability_name ] ) ) {
			return new WP_Error(
				'mad4b_projection_execution_seal_required',
				$required
					? 'Projected execution reached the final callback without the required fresh pre-tool admission seal.'
					: 'Projected execution requires a fresh final pre-tool admission seal.'
			);
		}
		$seal = self::$projected_call_seals[ $ability_name ];
		unset( self::$projected_call_seals[ $ability_name ] );
		$input_sha = self::projected_call_input_sha256( $input );
		if ( '' === $input_sha || ! hash_equals( (string) $seal['input_sha256'], $input_sha ) ) {
			return new WP_Error( 'mad4b_projection_execution_seal_mismatch', 'Projected call arguments changed after final pre-tool admission.' );
		}
		$identity = self::projected_execution_identity( $ability_name );
		if ( is_wp_error( $identity ) ) return $identity;
		$identity_sha = self::digest( self::PROJECTED_CALL_SEAL_CONTRACT, $identity );
		if ( '' === $identity_sha || ! hash_equals( (string) $seal['identity_sha256'], $identity_sha ) ) {
			return new WP_Error( 'mad4b_projection_execution_identity_drift', 'Projected execution identity changed after final pre-tool admission.' );
		}
		if ( ! class_exists( 'MAD4B_SCP_ChatGPT_Tool_Projection' )
			|| ! MAD4B_SCP_ChatGPT_Tool_Projection::materialized_tool_matches( $seal['tool'], $seal['server'] ) ) {
			return new WP_Error( 'mad4b_projection_materialized_drift', 'Projected tool materialization changed after final pre-tool admission.' );
		}
		return true;
	}

	public static function projected_call_requires_seal( $ability_name ) {
		$ability_name = (string) $ability_name;
		if ( ! class_exists( 'MAD4B_SCP_Transport_Context' ) || 'mad4b-chatgpt' !== MAD4B_SCP_Transport_Context::current_server_id() ) return false;
		if ( class_exists( 'MAD4B_SCP_Servers' ) && in_array( $ability_name, MAD4B_SCP_Servers::chatgpt_base_tools(), true ) ) return false;
		if ( self::projected_call_requirement_pending( $ability_name ) ) return true;
		return class_exists( 'MAD4B_SCP_ChatGPT_Tool_Projection' ) && MAD4B_SCP_ChatGPT_Tool_Projection::is_projected( $ability_name );
	}

	public static function has_active_frame() {
		return ! empty( self::$execution_stack );
	}

	public static function with_governed_child( $ability_name, $input, $callback, $reason = 'dispatcher' ) {
		if ( ! is_callable( $callback ) ) return new WP_Error( 'mad4b_child_operation_callback_invalid', 'Governed child operation requires a callable target.' );
		if ( empty( self::$execution_stack ) ) return new WP_Error( 'mad4b_child_operation_parent_required', 'Governed child operation requires an active parent execution frame.' );
		if ( ! empty( self::$child_permit ) ) return new WP_Error( 'mad4b_child_operation_permit_pending', 'Another governed child permit is already pending.' );
		$parent = self::$execution_stack[ count( self::$execution_stack ) - 1 ];
		$child_input_sha = self::digest( 'mad4b.child-operation-input.v1', $input );
		$child_evidence_sha = self::evidence_sha256( $input );
		if ( '' === $child_input_sha || '' === $child_evidence_sha ) return new WP_Error( 'mad4b_child_operation_digest_failed', 'Governed child operation could not bind the exact child input/evidence.' );
		try {
			$token = bin2hex( random_bytes( 16 ) );
		} catch ( Throwable $error ) {
			return new WP_Error( 'mad4b_child_operation_token_unavailable', 'Governed child operation token could not be generated.' );
		}
		self::$child_permit = array(
			'contract' => self::CHILD_OPERATION_CONTRACT,
			'token' => $token,
			'parent_frame_id' => (string) $parent['frame_id'],
			'parent_evidence_sha256' => (string) $parent['evidence_sha256'],
			'child_ability' => (string) $ability_name,
			'child_input_sha256' => $child_input_sha,
			'child_evidence_sha256' => $child_evidence_sha,
			'reason' => sanitize_key( (string) $reason ),
		);
		try {
			return call_user_func( $callback );
		} finally {
			if ( isset( self::$child_permit['token'] ) && hash_equals( $token, (string) self::$child_permit['token'] ) ) self::$child_permit = array();
		}
	}

	public static function governed_child_permit_matches( $ability_name, $input ) {
		if ( empty( self::$execution_stack ) || empty( self::$child_permit ) ) return false;
		$parent = self::$execution_stack[ count( self::$execution_stack ) - 1 ];
		$permit = self::$child_permit;
		$input_sha = self::digest( 'mad4b.child-operation-input.v1', $input );
		$evidence_sha = self::evidence_sha256( $input );
		return self::CHILD_OPERATION_CONTRACT === ( isset( $permit['contract'] ) ? (string) $permit['contract'] : '' )
			&& isset( $permit['parent_frame_id'] ) && hash_equals( (string) $parent['frame_id'], (string) $permit['parent_frame_id'] )
			&& isset( $permit['child_ability'] ) && hash_equals( (string) $permit['child_ability'], (string) $ability_name )
			&& isset( $permit['child_input_sha256'] ) && '' !== $input_sha && hash_equals( (string) $permit['child_input_sha256'], $input_sha )
			&& isset( $permit['child_evidence_sha256'] ) && '' !== $evidence_sha && hash_equals( (string) $permit['child_evidence_sha256'], $evidence_sha );
	}

	public static function status() {
		return array(
			'contract' => self::CONTRACT,
			'final_admission_contract' => self::FINAL_ADMISSION_CONTRACT,
			'projected_call_seal_contract' => self::PROJECTED_CALL_SEAL_CONTRACT,
			'child_operation_contract' => self::CHILD_OPERATION_CONTRACT,
			'execution_depth' => count( self::$execution_stack ),
			'child_permit_pending' => ! empty( self::$child_permit ),
			'projected_seal_count' => count( self::$projected_call_seals ),
			'projected_requirement_count' => count( self::$projected_call_requirements ),
			'authorizing' => false,
		);
	}

	public static function reset_request_cache() {
		if ( ! empty( self::$execution_stack ) || ! empty( self::$child_permit ) ) {
			return new WP_Error( 'mad4b_request_scope_execution_fence_active', 'Execution frames or governed child permits remain active across the request boundary.' );
		}
		self::$entries = array();
		self::$projected_call_seals = array();
		self::$projected_call_requirements = array();
		self::$trusted_final_boundaries = array();
		return true;
	}

	public static function enter_execution_frame( $ability_name, $input, $mutation ) {
		$parent = empty( self::$execution_stack ) ? null : self::$execution_stack[ count( self::$execution_stack ) - 1 ];
		if ( $parent && ! empty( self::$child_permit ) ) {
			$permit = self::consume_child_permit( $parent, $ability_name, $input );
			if ( is_wp_error( $permit ) ) return $permit;
		} elseif ( $parent && $mutation ) {
			return new WP_Error( 'mad4b_recursive_dispatch_child_operation_required', 'Nested governed mutation requires an explicit one-time governed child operation.' );
		}
		try {
			$frame_id = bin2hex( random_bytes( 16 ) );
		} catch ( Throwable $error ) {
			return new WP_Error( 'mad4b_execution_frame_token_unavailable', 'Execution frame identity could not be generated.' );
		}
		$frame = array(
			'contract' => self::FINAL_ADMISSION_CONTRACT,
			'frame_id' => $frame_id,
			'ability_name' => (string) $ability_name,
			'mutation' => (bool) $mutation,
			'input_sha256' => self::digest( 'mad4b.execution-frame-input.v1', $input ),
			'evidence_sha256' => self::evidence_sha256( $input ),
			'parent_frame_id' => $parent ? (string) $parent['frame_id'] : '',
		);
		self::$execution_stack[] = $frame;
		return $frame_id;
	}

	public static function leave_execution_frame( $frame_id ) {
		if ( empty( self::$execution_stack ) ) return;
		$top = self::$execution_stack[ count( self::$execution_stack ) - 1 ];
		if ( isset( $top['frame_id'] ) && hash_equals( (string) $top['frame_id'], (string) $frame_id ) ) {
			array_pop( self::$execution_stack );
			return;
		}
		self::$execution_stack = array();
		self::$child_permit = array();
		self::$projected_call_seals = array();
		self::$projected_call_requirements = array();
	}

	private static function consume_child_permit( array $parent, $ability_name, $input ) {
		if ( empty( self::$child_permit ) ) {
			return new WP_Error( 'mad4b_recursive_dispatch_child_operation_required', 'Nested governed mutation requires an explicit one-time governed child operation.' );
		}
		$permit = self::$child_permit;
		self::$child_permit = array();
		$input_sha = self::digest( 'mad4b.child-operation-input.v1', $input );
		$evidence_sha = self::evidence_sha256( $input );
		$valid = self::CHILD_OPERATION_CONTRACT === ( isset( $permit['contract'] ) ? (string) $permit['contract'] : '' )
			&& isset( $permit['parent_frame_id'] ) && hash_equals( (string) $parent['frame_id'], (string) $permit['parent_frame_id'] )
			&& isset( $permit['child_ability'] ) && hash_equals( (string) $permit['child_ability'], (string) $ability_name )
			&& isset( $permit['child_input_sha256'] ) && '' !== $input_sha && hash_equals( (string) $permit['child_input_sha256'], $input_sha )
			&& isset( $permit['child_evidence_sha256'] ) && '' !== $evidence_sha && hash_equals( (string) $permit['child_evidence_sha256'], $evidence_sha );
		if ( ! $valid ) return new WP_Error( 'mad4b_child_operation_permit_mismatch', 'Governed child operation identity/evidence changed after the parent issued its one-time permit.' );
		return true;
	}

	private static function projected_execution_identity( $ability_name ) {
		if ( ! class_exists( 'MAD4B_SCP_ChatGPT_Tool_Projection' ) ) return new WP_Error( 'mad4b_projection_identity_unavailable', 'Projection identity service is unavailable.' );
		if ( ! self::final_execution_wrapper_verified( $ability_name ) ) return new WP_Error( 'mad4b_projection_final_execution_admission_required', 'Projected Ability is missing the final execution-admission wrapper.' );
		$row = MAD4B_SCP_ChatGPT_Tool_Projection::inspect_contract( $ability_name );
		if ( is_wp_error( $row ) ) return $row;
		$request_context_sha = '';
		if ( class_exists( 'MAD4B_SCP_Request_Generation' ) ) {
			$request = MAD4B_SCP_Request_Generation::admit( 'projected_execution_admission' );
			if ( is_wp_error( $request ) ) return $request;
			$request_context_sha = isset( $request['context_sha256'] ) ? (string) $request['context_sha256'] : '';
		}
		$binding = MAD4B_SCP_ChatGPT_Tool_Projection::current_binding();
		$binding_sha = self::digest( 'mad4b.projected-call-site-binding.v1', $binding );
		$pin_basis = array(
			'ability_name' => (string) $ability_name,
			'execution_lane' => isset( $row['lane'] ) ? (string) $row['lane'] : ( isset( $row['execution_lane'] ) ? (string) $row['execution_lane'] : '' ),
			'input_schema_sha256' => isset( $row['input_schema_sha256'] ) ? (string) $row['input_schema_sha256'] : '',
			'classification_sha256' => isset( $row['classification_sha256'] ) ? (string) $row['classification_sha256'] : '',
			'site_binding_sha256' => $binding_sha,
		);
		$structural_pin_sha = self::digest( 'mad4b.projected-call-structural-pins.v1', $pin_basis );
		if ( '' === $binding_sha || '' === $structural_pin_sha ) return new WP_Error( 'mad4b_projection_execution_pin_digest_failed', 'Projected execution structural pins could not be derived.' );
		return array_merge( $pin_basis, array(
			'structural_pin_sha256' => $structural_pin_sha,
			'authority_scope_sha256' => class_exists( 'MAD4B_SCP_Ability_Catalog_Transport' ) ? MAD4B_SCP_Ability_Catalog_Transport::current_authority_scope() : '',
			'request_context_sha256' => $request_context_sha,
		) );
	}

	private static function projected_call_input_sha256( $input ) {
		// MCP `tools/call` arguments are an object. For an empty object the Adapter
		// pre-tool hook may expose `array()` while WP_Ability invokes a callback
		// with its default `null`. Canonicalize only that protocol-equivalent empty
		// representation; every non-empty argument remains byte-semantically bound.
		if ( null === $input ) $input = array();
		return self::digest( 'mad4b.projected-call-input.v1', $input );
	}

	private static function evidence_sha256( $input ) {
		$evidence = array();
		self::collect_evidence( $input, '', $evidence );
		ksort( $evidence, SORT_STRING );
		return self::digest( 'mad4b.execution-evidence.v1', $evidence );
	}

	private static function collect_evidence( $value, $path, array &$evidence ) {
		if ( ! is_array( $value ) ) return;
		$interesting = array(
			'_mad4b_approval_ticket_id', 'approval_ticket_id',
			'preparation_receipt', '_mad4b_context_receipt', 'context_receipt',
			'idempotency_key', '_mad4b_idempotency_key', 'operation_id',
			'execution_id', 'claim_epoch', 'request_sha256',
		);
		foreach ( $value as $key => $item ) {
			$key_string = strtolower( (string) $key );
			$item_path = '' === $path ? $key_string : $path . '.' . $key_string;
			if ( in_array( $key_string, $interesting, true ) ) {
				$evidence[ $item_path ] = self::digest( 'mad4b.execution-evidence-field.v1', $item );
			}
			if ( is_array( $item ) ) self::collect_evidence( $item, $item_path, $evidence );
		}
	}

	private static function digest( $domain, $value ) {
		if ( class_exists( 'MAD4B_SCP_Canonicalization' ) ) {
			$digest = MAD4B_SCP_Canonicalization::digest( (string) $domain, $value );
			if ( ! is_wp_error( $digest ) && is_string( $digest ) ) return strtolower( $digest );
		}
		$normalized = self::canonicalize( $value );
		$json = function_exists( 'wp_json_encode' )
			? wp_json_encode( $normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
			: json_encode( $normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return is_string( $json ) ? hash( 'sha256', (string) $domain . "\n" . $json ) : '';
	}

	private static function canonicalize( $value ) {
		if ( is_array( $value ) ) {
			$keys = array_keys( $value );
			$is_list = $keys === range( 0, count( $value ) - 1 );
			if ( $is_list ) {
				$out = array();
				foreach ( $value as $item ) $out[] = self::canonicalize( $item );
				return $out;
			}
			sort( $keys, SORT_STRING );
			$out = array();
			foreach ( $keys as $key ) $out[ (string) $key ] = self::canonicalize( $value[ $key ] );
			return $out;
		}
		if ( is_object( $value ) ) return self::canonicalize( get_object_vars( $value ) );
		if ( is_string( $value ) || is_int( $value ) || is_bool( $value ) || null === $value ) return $value;
		if ( is_float( $value ) && is_finite( $value ) ) return $value;
		return (string) $value;
	}

	public static function key_for( $ability_name, $declared_server, $input ) {
		if ( ! class_exists( 'MAD4B_SCP_Identity_Context' ) || ! class_exists( 'MAD4B_SCP_Authorization' ) ) return '';
		$identity = MAD4B_SCP_Identity_Context::current();
		if ( is_wp_error( $identity ) || ! is_array( $identity ) ) return '';
		$request_id = isset( $identity['request_id'] ) ? trim( (string) $identity['request_id'] ) : '';
		if ( '' === $request_id ) return '';

		$input_ticket = class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) ? MAD4B_SCP_Staging_Write_Authority::approval_ticket_from_input( $input ) : '';
		$identity_ticket = isset( $identity['approval_ticket_id'] ) ? strtolower( trim( (string) $identity['approval_ticket_id'] ) ) : '';
		$ticket_id = '' !== $input_ticket ? $input_ticket : $identity_ticket;
		if ( '' === $ticket_id || ! preg_match( '/^[a-f0-9-]{36}$/', $ticket_id ) ) return '';

		$provider = MAD4B_SCP_Authorization::provider_for_execution( $ability_name, $declared_server );
		if ( is_wp_error( $provider ) ) return '';
		$authorization_input = class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) ? MAD4B_SCP_Staging_Write_Authority::authorization_input( $input ) : $input;
		$agent = array();
		if ( class_exists( 'MAD4B_SCP_Agent_Registry' ) ) {
			$resolved = MAD4B_SCP_Agent_Registry::resolve_agent( $identity );
			if ( is_array( $resolved ) ) $agent = $resolved;
		}
		$target = MAD4B_SCP_Authorization::target_fingerprint( $ability_name, $provider, $authorization_input, $agent, $identity );
		if ( '' === $target ) return '';

		$payload = array(
			'contract' => self::CONTRACT,
			'request_id' => substr( $request_id, 0, 64 ),
			'approval_ticket_id' => $ticket_id,
			'ability' => (string) $ability_name,
			'provider' => (string) $provider,
			'target_fingerprint' => $target,
		);
		$encoded = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return false === $encoded ? '' : hash( 'sha256', $encoded );
	}

	private static function declared_server( array $args ) {
		$meta = isset( $args['meta'] ) && is_array( $args['meta'] ) ? $args['meta'] : array();
		$mcp = isset( $meta['mcp'] ) && is_array( $meta['mcp'] ) ? $meta['mcp'] : array();
		$surface = isset( $mcp['surface'] ) ? sanitize_key( (string) $mcp['surface'] ) : '';
		if ( in_array( $surface, array( 'content', 'write', 'admin', 'breakglass', 'developer', 'developer-breakglass' ), true ) ) return 'mad4b-' . $surface;
		$category = isset( $args['category'] ) ? sanitize_key( (string) $args['category'] ) : '';
		if ( in_array( $category, array( 'mad4b-content', 'mad4b-admin', 'mad4b-breakglass', 'mad4b-developer', 'mad4b-developer-breakglass' ), true ) ) return $category;
		return 'mad4b-write';
	}
}

require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-live-acceptance-reconciler.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-portable-snapshot-attestation.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-external-snapshot-finalizer.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-external-wpml-acceptance-finalizer.php';

if ( function_exists( 'add_filter' ) ) MAD4B_SCP_Execution_Fence::boot();
