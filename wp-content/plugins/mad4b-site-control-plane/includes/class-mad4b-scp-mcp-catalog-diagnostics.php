<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Bounded request-local evidence; never repairs registrations or grants. */
final class MAD4B_SCP_MCP_Catalog_Diagnostics {
	const CONTRACT = 'mad4b.mcp-catalog-evidence.v1';
	const MAX_TOOLS = 36;
	const MAX_SERIALIZED_TOOL_BYTES = 98304;
	private static $booted = false;
	private static $failure_logged = false;
	private static $registered_classification = array();
	private static $classification_server = '';
	private static $registered_callbacks = array();

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;
		add_filter( 'mcp_adapter_tools_list', array( __CLASS__, 'filter_serializable_tools' ), PHP_INT_MAX, 2 );
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'observe_response' ), PHP_INT_MAX, 3 );
	}

	/** Convert each requested ability using the same official builder as registration. No execution. */
	public static function budget_projection( array $abilities, array $optional ) {
		$abilities = array_values( array_map( 'strval', $abilities ) );
		$optional = array_values( array_unique( array_map( 'strval', $optional ) ) );
		if ( count( array_unique( $abilities ) ) !== count( $abilities ) ) {
			return array( 'ready' => false, 'blocker' => 'mcp_catalog_duplicate_ability', 'selected' => array(), 'excluded_optional' => array() );
		}
		$required = array_values( array_diff( $abilities, $optional ) );
		if ( count( $required ) > self::MAX_TOOLS ) {
			return array( 'ready' => false, 'blocker' => 'mcp_required_catalog_budget_exceeded', 'selected' => array(), 'excluded_optional' => array() );
		}
		$capacity = max( 0, self::MAX_TOOLS - count( $required ) );
		$optional_requested = array_values( array_intersect( $optional, $abilities ) );
		$optional_kept = array_slice( $optional_requested, 0, $capacity );
		$optional_excluded = array_slice( $optional_requested, $capacity );
		$allow = array_fill_keys( array_merge( $required, $optional_kept ), true );
		$selected = array_values( array_filter( $abilities, static function ( $name ) use ( $allow ) { return isset( $allow[ $name ] ); } ) );
		return array(
			'ready' => true,
			'blocker' => '',
			'selected' => $selected,
			'excluded_optional' => $optional_excluded,
			'required_count' => count( $required ),
			'optional_capacity' => $capacity,
		);
	}

	public static function preflight( array $abilities, array $optional ) {
		$out = array( 'ready' => false, 'degraded' => false, 'tools' => array(), 'failures' => array(), 'blocker' => '' );
		$abilities = array_values( array_map( 'strval', $abilities ) );
		$optional = array_values( array_unique( array_map( 'strval', $optional ) ) );
		// Required transport tools are never sacrificed to fit the client refresh
		// budget. Reviewed direct step-ups may be deterministically omitted.
		$budget = self::budget_projection( $abilities, $optional );
		if ( empty( $budget['ready'] ) ) { $out['blocker'] = $budget['blocker']; return $out; }
		$budget_allow = array_fill_keys( $budget['selected'], true );
		foreach ( $budget['excluded_optional'] as $name ) {
			$out['failures'][] = array(
				'stage' => 'catalog_budget',
				'error_class' => 'Budget',
				'error_code' => 'mcp_optional_catalog_budget_excluded',
				'schema_fingerprint' => '',
				'source_schema_fingerprint' => '',
				'failing_ability' => $name,
			);
		}

		if ( ! class_exists( 'WP\\MCP\\Domain\\Tools\\RegisterAbilityAsMcpTool' ) || ! class_exists( 'WP\\MCP\\Domain\\Tools\\McpToolValidator' ) || ! function_exists( 'wp_get_ability' ) ) { $out['blocker'] = 'mcp_catalog_builder_unavailable'; return $out; }
		$names = array(); $catalog_bytes = 32;
		// Validate required tools before optional projections. Direct tools must fit
		// the same bounded tools/list envelope exercised by HTTP acceptance; large
		// optional Abilities remain reachable through the stable dispatcher path.
		$ordered = array_merge( array_values( array_diff( $abilities, $optional ) ), array_values( array_intersect( $abilities, $optional ) ) );
		foreach ( $ordered as $name ) {
			if ( ! isset( $budget_allow[ $name ] ) ) continue;
			$stage = 'ability_lookup'; $failure = null; $source_fingerprint = '';
			try {
				$ability = wp_get_ability( $name );
				if ( ! $ability ) throw new RuntimeException( 'ability_missing' );
				$source_fingerprint = hash( 'sha256', serialize( array( $ability->get_input_schema(), $ability->get_output_schema() ) ) );
				$stage = 'official_dto_build';
				$built = \WP\MCP\Domain\Tools\RegisterAbilityAsMcpTool::build( $ability );
				if ( is_wp_error( $built ) ) { $failure = array( 'stage' => $stage, 'error_class' => 'WP_Error', 'error_code' => sanitize_key( $built->get_error_code() ), 'schema_fingerprint' => '' ); }
				else {
					$failure = self::dto_failure( $built['tool'] );
					$tool_bytes = strlen( json_encode( $built['tool']->toArray(), JSON_THROW_ON_ERROR ) ) + 1;
					if ( ! $failure && ( ! isset( $built['adapter_meta']['ability'] ) || $name !== $built['adapter_meta']['ability'] || isset( $names[ $built['tool']->getName() ] ) ) ) $failure = array( 'stage' => 'identity', 'error_class' => 'Contract', 'error_code' => 'mcp_identity_collision', 'schema_fingerprint' => '' );
					if ( ! $failure && $catalog_bytes + $tool_bytes > self::MAX_SERIALIZED_TOOL_BYTES ) {
						$failure = array(
							'stage' => 'catalog_size',
							'error_class' => 'Budget',
							'error_code' => in_array( $name, $optional, true ) ? 'mcp_optional_catalog_size_excluded' : 'mcp_required_catalog_size_exceeded',
							'schema_fingerprint' => '',
							'tool_bytes' => $tool_bytes,
							'catalog_bytes_before' => max( 0, $catalog_bytes - 32 ),
							'catalog_bytes_limit' => self::MAX_SERIALIZED_TOOL_BYTES,
						);
					}
					if ( ! $failure ) { $names[ $built['tool']->getName() ] = true; $catalog_bytes += $tool_bytes; }
				}
			} catch ( Throwable $error ) { $failure = array( 'stage' => $stage, 'error_class' => get_class( $error ), 'error_code' => 'mcp_preflight_exception', 'schema_fingerprint' => '' ); }
			if ( $failure ) {
				$failure['failing_ability'] = $name; $failure['source_schema_fingerprint'] = $source_fingerprint; $out['failures'][] = $failure;
				// Only explicitly classified reviewed direct step-ups may degrade.
				// Identity conflicts never degrade because they make routing ambiguous.
				if ( ! in_array( $name, $optional, true ) || 'identity' === $failure['stage'] ) $out['blocker'] = 'mcp_required_tool_preflight_failed';
			} else $out['tools'][] = $name;
		}
		$out['serialized_tool_bytes'] = max( 0, $catalog_bytes - 32 );
		$out['serialized_tool_bytes_limit'] = self::MAX_SERIALIZED_TOOL_BYTES;
		$out['size_policy'] = 'bounded_serialized_tool_bytes';
		$out['degraded'] = ! empty( $out['failures'] ) && '' === $out['blocker'];
		$out['ready'] = '' === $out['blocker'] && ! empty( $out['tools'] );
		if ( ! $out['ready'] && '' === $out['blocker'] ) $out['blocker'] = 'mcp_catalog_empty';
		return $out;
	}

	/** Official validator plus exact wire serialization. Only bounded diagnostic fields escape. */
	public static function dto_failure( $dto ) {
		$stage = 'dto_serialization'; $fingerprint = '';
		try {
			if ( ! is_object( $dto ) || ! method_exists( $dto, 'toArray' ) ) throw new RuntimeException( 'dto_unavailable' );
			$data = $dto->toArray();
			$fingerprint = hash( 'sha256', serialize( array( $data['inputSchema'] ?? null, $data['outputSchema'] ?? null ) ) );
			$json = json_encode( $data, JSON_THROW_ON_ERROR );
			$stage = 'official_schema_validation';
			if ( class_exists( 'WP\\MCP\\Domain\\Tools\\McpToolValidator' ) ) {
				$valid = \WP\MCP\Domain\Tools\McpToolValidator::validate_tool_dto( $dto );
				if ( is_wp_error( $valid ) ) return array( 'stage' => $stage, 'error_class' => 'WP_Error', 'error_code' => sanitize_key( $valid->get_error_code() ), 'schema_fingerprint' => $fingerprint );
			}
			$stage = 'wire_schema_shape'; $wire = json_decode( $json );
			if ( ! is_object( $wire ) || ! isset( $wire->inputSchema ) || ! is_object( $wire->inputSchema ) || 'object' !== ( $wire->inputSchema->type ?? '' ) || ! isset( $wire->inputSchema->properties ) || ! is_object( $wire->inputSchema->properties ) ) throw new RuntimeException( 'wire_input_schema_invalid' );
			if ( isset( $wire->outputSchema ) && ( ! is_object( $wire->outputSchema ) || 'object' !== ( $wire->outputSchema->type ?? '' ) ) ) throw new RuntimeException( 'wire_output_schema_invalid' );
			return null;
		} catch ( Throwable $error ) { return array( 'stage' => $stage, 'error_class' => get_class( $error ), 'error_code' => 'mcp_tool_serialization_invalid', 'schema_fingerprint' => $fingerprint ); }
	}

	/** Freeze reviewed identity/classification once, at server materialization, before OAuth. */
	public static function capture_classification( $server, array $expected ) {
		if ( ! is_object( $server ) || ! method_exists( $server, 'get_tools' ) ) throw new RuntimeException( 'mad4b_catalog_capture_unavailable' );
		$reviewed = MAD4B_SCP_Servers::chatgpt_reviewed_direct_step_up_tools();
		$dynamic_rows = class_exists( 'MAD4B_SCP_ChatGPT_Tool_Projection' )
			? MAD4B_SCP_ChatGPT_Tool_Projection::effective_projection_rows()
			: array();
		$captured = array();
		foreach ( $server->get_tools() as $name => $dto ) {
			$bound = $server->get_mcp_tool( $name );
			$meta = $bound ? $bound->get_adapter_meta() : array();
			$ability = isset( $meta['ability'] ) ? (string) $meta['ability'] : '';
			if ( ! in_array( $ability, $expected, true ) || $name !== $dto->getName() ) throw new RuntimeException( 'mad4b_catalog_capture_identity_invalid' );
			$dynamic = isset( $dynamic_rows[ $ability ] ) && is_array( $dynamic_rows[ $ability ] );
			$dynamic_readonly = $dynamic && 'read' === $dynamic_rows[ $ability ]['lane'] && ! empty( $dynamic_rows[ $ability ]['readonly'] );
			$dynamic_breakglass = $dynamic && ! empty( $dynamic_rows[ $ability ]['breakglass'] );
			$direct_step_up = in_array( $ability, $reviewed, true );
			$captured[ $name ] = array(
				'ability' => $ability,
				'direct_step_up' => $direct_step_up,
				'dynamic_projection' => $dynamic,
				'input_schema_sha256' => $dynamic ? $dynamic_rows[ $ability ]['input_schema_sha256'] : '',
				'classification_sha256' => $dynamic ? $dynamic_rows[ $ability ]['classification_sha256'] : '',
				'wire_sha256' => $dynamic ? hash( 'sha256', wp_json_encode( $dto->toArray() ) ) : '',
				'dynamic_readonly' => $dynamic_readonly,
				'dynamic_breakglass' => $dynamic_breakglass,
				'exact_chatgpt_client_required' => $direct_step_up || ( $dynamic && ! $dynamic_readonly ),
			);
		}
		if ( ! empty( self::$registered_classification ) && self::$registered_classification !== $captured ) throw new RuntimeException( 'mad4b_catalog_classification_changed' );
		self::$registered_classification = $captured;
		self::$classification_server = spl_object_hash( $server );
		foreach ( $captured as $name => $row ) if ( $row['dynamic_projection'] ) self::$registered_callbacks[ $name ] = MAD4B_SCP_ChatGPT_Tool_Projection::callback_identity( $server->get_mcp_tool( $name ) );
	}

	public static function materialized_callbacks_match( $tool, $server ) {
		$name = $tool->get_protocol_dto()->getName();
		return spl_object_hash( $server ) === self::$classification_server && isset( self::$registered_callbacks[ $name ] ) && self::$registered_callbacks[ $name ] === MAD4B_SCP_ChatGPT_Tool_Projection::callback_identity( $tool );
	}

	public static function classification_snapshot() { return self::$registered_classification; }

	/** Deny-only final filter: never restore tools removed by earlier role/authority filters. */
	private static function ability_is_direct_step_up( $ability_name ) {
		$ability_name = (string) $ability_name;
		if ( '' === $ability_name || ! function_exists( 'wp_has_ability' ) || ! function_exists( 'wp_get_ability' ) || ! wp_has_ability( $ability_name ) ) return false;
		$ability = wp_get_ability( $ability_name );
		if ( ! is_object( $ability ) || ! method_exists( $ability, 'get_meta' ) ) return false;
		$meta = $ability->get_meta();
		$mcp = isset( $meta['mcp'] ) && is_array( $meta['mcp'] ) ? $meta['mcp'] : array();
		$annotations = isset( $meta['annotations'] ) && is_array( $meta['annotations'] ) ? $meta['annotations'] : array();
		return ! empty( $mcp['chatgpt_direct_step_up'] )
			&& ! empty( $mcp['exact_chatgpt_client_required'] )
			&& 'enrollment' === ( isset( $mcp['surface'] ) ? (string) $mcp['surface'] : '' )
			&& array_key_exists( 'readonly', $annotations )
			&& false === $annotations['readonly'];
	}

	/** Deny-only final filter: never restore tools removed by earlier role/authority filters. */
	public static function filter_serializable_tools( $tools, $server ) {
		if ( ! is_array( $tools ) || ! is_object( $server ) || ! method_exists( $server, 'get_server_id' ) || 'mad4b-chatgpt' !== $server->get_server_id() ) return $tools;
		$step_up_visible = class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge', false )
			&& MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_active()
			&& MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_has_scope( MAD4B_SCP_OAuth_Resource_Bridge::AUTHORITY_STEP_UP_SCOPE )
			&& class_exists( 'MAD4B_SCP_Local_OAuth_Server', false )
			&& MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_client_is( MAD4B_SCP_Local_OAuth_Server::CHATGPT_CIMD_CLIENT_ID );
		$safe = array();
		foreach ( $tools as $dto ) {
			$name = is_object( $dto ) && method_exists( $dto, 'getName' ) ? $dto->getName() : '';
			$bound = $name ? $server->get_mcp_tool( $name ) : null;
			$meta = is_object( $bound ) && method_exists( $bound, 'get_adapter_meta' ) ? $bound->get_adapter_meta() : array();
			$ability = is_array( $meta ) && isset( $meta['ability'] ) ? (string) $meta['ability'] : '';
			$captured = isset( self::$registered_classification[ $name ] ) ? self::$registered_classification[ $name ] : null;
			if ( spl_object_hash( $server ) !== self::$classification_server || ! is_array( $captured ) || $ability !== $captured['ability'] ) throw new RuntimeException( 'mad4b_catalog_classification_unavailable' );
			$direct_step_up = ! empty( $captured['direct_step_up'] );
			$dynamic = ! empty( $captured['dynamic_projection'] );
			$dynamic_readonly = ! empty( $captured['dynamic_readonly'] );
			$dynamic_breakglass = ! empty( $captured['dynamic_breakglass'] );
			if ( $dynamic && ! MAD4B_SCP_ChatGPT_Tool_Projection::materialized_tool_matches( $bound, $server ) ) continue;
			if ( $dynamic && ( ! class_exists( 'MAD4B_SCP_ChatGPT_Tool_Projection' ) || ! MAD4B_SCP_ChatGPT_Tool_Projection::is_projected( $ability ) ) ) continue;

			// Dynamic projection changes discoverability only. Mutating projections
			// require the exact ChatGPT step-up bearer for visibility; the original
			// Ability permission callback remains authoritative for execution.
			if ( ( $direct_step_up || ( $dynamic && ! $dynamic_readonly ) ) && ! $step_up_visible ) continue;
			if ( $dynamic_breakglass ) {
				$breakglass_scope_visible = class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge', false )
					&& MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_active()
					&& (
						MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_has_scope( MAD4B_SCP_OAuth_Resource_Bridge::BREAKGLASS_SCOPE )
						|| ( '' !== $ability && MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_has_scope( 'ability:' . $ability ) )
					);
				if ( ! $breakglass_scope_visible || ! class_exists( 'MAD4B_SCP_Policy' ) || ! MAD4B_SCP_Policy::can_breakglass() ) continue;
			}

			$failure = self::dto_failure( $dto );
			if ( ! $failure ) { $safe[] = $dto; continue; }
			if ( ! $direct_step_up && ! $dynamic ) throw new RuntimeException( 'mad4b_required_catalog_schema_invalid' );
			$failure['failing_ability'] = $ability;
			if ( ! self::$failure_logged ) {
				self::$failure_logged = true;
				error_log( '[MAD4B MCP preflight] ' . wp_json_encode( $failure ) );
			}
		}
		return $safe;
	}

	public static function optional_projections( array $abilities = array() ) {
		// Required base transport tools never degrade. Reviewed direct step-ups and
		// explicitly persisted dynamic projections are optional isolation units.
		if ( empty( $abilities ) && ! empty( self::$registered_classification ) ) $abilities = array_column( self::$registered_classification, 'ability' );
		elseif ( empty( $abilities ) && class_exists( 'MAD4B_SCP_Servers', false ) ) {
			$abilities = array_merge(
				MAD4B_SCP_Servers::chatgpt_reviewed_direct_step_up_tools(),
				class_exists( 'MAD4B_SCP_ChatGPT_Tool_Projection' ) ? MAD4B_SCP_ChatGPT_Tool_Projection::projected_ability_names() : array()
			);
		}
		$dynamic = class_exists( 'MAD4B_SCP_ChatGPT_Tool_Projection' )
			? array_fill_keys( MAD4B_SCP_ChatGPT_Tool_Projection::projected_ability_names(), true )
			: array();
		$optional = array();
		foreach ( array_values( array_unique( array_map( 'strval', $abilities ) ) ) as $ability_name ) {
			if ( isset( $dynamic[ $ability_name ] ) ) {
				$optional[] = $ability_name;
				continue;
			}
			if ( ! empty( self::$registered_classification ) ) {
				foreach ( self::$registered_classification as $captured ) {
					if ( $ability_name === $captured['ability'] && ! empty( $captured['direct_step_up'] ) ) $optional[] = $ability_name;
				}
			} elseif ( self::ability_is_direct_step_up( $ability_name ) ) {
				$optional[] = $ability_name;
			}
		}
		return array_values( array_unique( $optional ) );
	}

	public static function inspect( $server, array $expected_abilities ) {
		$out = array( 'contract' => self::CONTRACT, 'observed' => false, 'ready' => false, 'tool_count' => 0, 'actual_names' => array(), 'actual_abilities' => array(), 'missing_abilities' => array(), 'unexpected_abilities' => array(), 'blocker' => 'mcp_server_not_materialized' );
		if ( ! is_object( $server ) || ! method_exists( $server, 'get_tools' ) || ! method_exists( $server, 'get_mcp_tool' ) ) return $out;
		try {
			$tools = $server->get_tools();
			if ( ! is_array( $tools ) ) return $out;
			$out['observed'] = true;
			$out['tool_count'] = count( $tools );
			if ( count( $tools ) > self::MAX_TOOLS || count( $expected_abilities ) > self::MAX_TOOLS ) { $out['blocker'] = 'mcp_catalog_budget_exceeded'; return $out; }
			foreach ( $tools as $name => $dto ) {
				if ( ! is_string( $name ) || ! preg_match( '/^[A-Za-z0-9_.-]{1,128}$/D', $name ) || ! is_object( $dto ) || ! method_exists( $dto, 'getName' ) || $name !== $dto->getName() ) { $out['blocker'] = 'mcp_tool_identity_invalid'; return $out; }
				$tool = $server->get_mcp_tool( $name );
				$meta = is_object( $tool ) && method_exists( $tool, 'get_adapter_meta' ) ? $tool->get_adapter_meta() : array();
				$ability = is_array( $meta ) && isset( $meta['ability'] ) && is_string( $meta['ability'] ) ? $meta['ability'] : '';
				if ( ! preg_match( '/^[a-z0-9_-]+\/[a-z0-9_-]{1,128}$/D', $ability ) ) { $out['blocker'] = 'mcp_tool_ability_binding_invalid'; return $out; }
				$failure = self::dto_failure( $dto );
				if ( $failure ) { $failure['failing_ability'] = $ability; $out['serialization_failure'] = $failure; $out['blocker'] = 'mcp_actual_tool_serialization_failed'; return $out; }
				$out['actual_names'][] = $name;
				$out['actual_abilities'][] = $ability;
			}
			sort( $out['actual_names'], SORT_STRING );
			sort( $out['actual_abilities'], SORT_STRING );
			$out['missing_abilities'] = array_values( array_diff( $expected_abilities, $out['actual_abilities'] ) );
			$out['unexpected_abilities'] = array_values( array_diff( $out['actual_abilities'], $expected_abilities ) );
			sort( $out['missing_abilities'], SORT_STRING );
			sort( $out['unexpected_abilities'], SORT_STRING );
			$out['inventory_fingerprint'] = hash( 'sha256', wp_json_encode( array( $out['actual_names'], $out['actual_abilities'] ) ) );
			$out['blocker'] = empty( $tools ) ? 'mcp_catalog_empty' : ( count( array_unique( $out['actual_abilities'] ) ) !== count( $tools ) ? 'mcp_catalog_duplicate_ability' : ( ! empty( $out['missing_abilities'] ) || ! empty( $out['unexpected_abilities'] ) ? 'mcp_catalog_projection_mismatch' : '' ) );
			$out['ready'] = '' === $out['blocker'];
		} catch ( Throwable $error ) { $out['blocker'] = 'mcp_catalog_inspection_failed'; }
		return $out;
	}

	public static function observe_response( $response, $rest_server, $request ) {
		unset( $rest_server );
		if ( ! is_object( $request ) || ! method_exists( $request, 'get_route' ) || '/mcp/mad4b-chatgpt' !== rtrim( (string) $request->get_route(), '/' ) || 'POST' !== $request->get_method() ) return $response;
		if ( ! class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge', false ) || ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_active() ) return $response;
		$body = $request->get_json_params();
		$method = is_array( $body ) && isset( $body['method'] ) && is_string( $body['method'] ) ? $body['method'] : '';
		if ( ! in_array( $method, array( 'initialize', 'tools/list' ), true ) || ! is_object( $response ) || ! method_exists( $response, 'get_data' ) || ! method_exists( $response, 'header' ) ) return $response;
		$data = $response->get_data();
		if ( $data instanceof stdClass ) $data = (array) $data;
		if ( is_object( $data ) && method_exists( $data, 'toArray' ) ) {
			try { $data = $data->toArray(); } catch ( Throwable $error ) { $data = null; }
		}
		// Adapter success results can still be official DTOs at this WordPress filter seam.
		if ( is_array( $data ) && isset( $data['result'] ) && is_object( $data['result'] ) && method_exists( $data['result'], 'toArray' ) ) {
			try { $data['result'] = $data['result']->toArray(); } catch ( Throwable $error ) { $data['result'] = null; }
		}
		if ( is_array( $data ) && isset( $data['result'] ) && $data['result'] instanceof stdClass ) $data['result'] = (array) $data['result'];
		$status = (int) $response->get_status();
		$outcome = $status >= 400 ? 'http_error' : ( ! is_array( $data ) || isset( $data['error'] ) || ! isset( $data['result'] ) || ! is_array( $data['result'] ) ? 'rpc_error' : 'ready' );
		if ( 'ready' === $outcome && 'initialize' === $method ) {
			$result = $data['result'];
			if ( ! isset( $result['protocolVersion'], $result['serverInfo'], $result['capabilities'] ) || ! is_string( $result['protocolVersion'] ) ) $outcome = 'rpc_error';
		}
		$count = null;
		if ( 'ready' === $outcome && 'tools/list' === $method ) {
			$listed = isset( $data['result']['tools'] ) ? $data['result']['tools'] : null;
			$count = is_array( $listed ) ? count( $listed ) : null;
			if ( null === $count ) $outcome = 'catalog_invalid';
			elseif ( 0 === $count ) $outcome = 'catalog_empty';
			elseif ( $count > self::MAX_TOOLS ) $outcome = 'catalog_budget_exceeded';
		}
		// No body, request ID, token, cookie, session value, or error message is copied.
		$evidence = array( 'contract' => self::CONTRACT, 'stage' => $method, 'http_status' => $status, 'outcome' => $outcome, 'session_header_present' => '' !== (string) $request->get_header( 'mcp-session-id' ), 'protocol_header_present' => '' !== (string) $request->get_header( 'mcp-protocol-version' ), 'tool_count' => $count );
		if ( is_array( $data ) && isset( $data['error'] ) && is_array( $data['error'] ) && isset( $data['error']['code'] ) && is_int( $data['error']['code'] ) ) $evidence['rpc_error_code'] = $data['error']['code'];
		$response->header( 'X-MAD4B-MCP-Stage', $method );
		$response->header( 'X-MAD4B-MCP-Outcome', $outcome );
		if ( 'ready' !== $outcome && ! self::$failure_logged ) {
			self::$failure_logged = true;
			error_log( '[MAD4B MCP discovery] ' . wp_json_encode( $evidence ) );
		}
		return $response;
	}
}
MAD4B_SCP_MCP_Catalog_Diagnostics::boot();
