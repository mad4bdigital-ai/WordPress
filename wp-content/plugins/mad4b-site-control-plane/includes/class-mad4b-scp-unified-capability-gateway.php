<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Client-adaptive capability gateway.
 *
 * This layer negotiates discovery/schema/exposure strategy only. Client claims
 * never grant authority, and this class never executes a target Ability. Actual
 * execution remains on the governed MCP dispatchers or an explicitly applied
 * dynamic projection, both of which re-check server-side authority.
 */
final class MAD4B_SCP_Unified_Capability_Gateway {
	const CONTRACT = 'mad4b.unified-capability-gateway.v1';
	const REST_NAMESPACE = 'mad4b/v1';
	const REST_ROUTE = '/capability-gateway';
	const MAX_TASK_BYTES = 512;
	const MAX_KEYWORDS = 24;
	const MAX_PREPARE = 16;
	const SEARCH_CURSOR_CONTRACT = 'mad4b.capability-search-cursor.v1';
	const SEARCH_CURSOR_TTL = 600;
	const DEFAULT_INLINE_SCHEMA_BYTES = 65536;
	const DEFAULT_MAX_RESPONSE_BYTES = 131072;

	private static $booted = false;
	private static $boot_blog_id = null;

	public static function boot() {
		if ( self::$booted || ! function_exists( 'add_action' ) ) return;
		self::$booted = true;
		self::$boot_blog_id = function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : 1;
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_route' ), 2 );
	}

	public static function runtime_blog_matches() {
		return null === self::$boot_blog_id || ! function_exists( 'get_current_blog_id' ) || self::$boot_blog_id === get_current_blog_id();
	}

	public static function rest_url() {
		return untrailingslashit( rest_url( self::REST_NAMESPACE . self::REST_ROUTE ) );
	}

	private static function fixed_dispatch_preparation_contract() {
		return array(
			'required' => true,
			'receipt_contract' => class_exists( 'MAD4B_SCP_Preparation_Receipt' ) ? MAD4B_SCP_Preparation_Receipt::CONTRACT : 'mad4b.preparation-receipt.v1',
			'descriptor_contract' => class_exists( 'MAD4B_SCP_Capability_Descriptor_Registry' ) ? MAD4B_SCP_Capability_Descriptor_Registry::CONTRACT : 'mad4b.capability-descriptor.v2',
			'classification_contract' => class_exists( 'MAD4B_SCP_Ability_Contract_Inspector' ) ? MAD4B_SCP_Ability_Contract_Inspector::CLASSIFICATION_CONTRACT : 'mad4b.ability-classification.v2',
			'required_identity_fields' => array(
				'expected_input_schema_sha256',
				'expected_execution_lane',
				'expected_classification_sha256',
				'expected_authority_scope_sha256',
				'preparation_receipt',
			),
			'live_authority_revalidation' => true,
			'authority_effect' => 'none',
		);
	}

	public static function public_manifest() {
		return array(
			'contract' => self::CONTRACT,
			'rest_url' => self::rest_url(),
			'rest_requires_oauth_bearer' => false,
			'rest_auth_modes' => array( 'oauth_bearer', 'authenticated_wordpress_session' ),
			'remote_client_auth_mode' => 'oauth_bearer',
			'wordpress_cookie_requires_rest_nonce' => true,
			'primary_execution_mode' => 'fixed_dispatch',
			'fixed_dispatch_preparation' => self::fixed_dispatch_preparation_contract(),
			'projection_role' => 'optional_hot_set',
			'server_tools_list_changed' => false,
			'actions' => array( 'negotiate', 'sources', 'search', 'dependencies', 'recovery', 'prepare', 'schema', 'chunk' ),
			'search_pagination' => array( 'mode' => 'opaque_signed_cursor', 'max_page_size' => 25, 'total_result_ceiling' => null, 'snapshot_drift' => 'reject_and_restart', 'authority_effect' => 'none' ),
			'search_filters' => array( 'category', 'surface', 'ability_prefix', 'declared_readonly_only', 'declared_writeonly_only', 'match_mode', 'sort', 'min_score', 'browse' ),
			'source_of_truth' => 'wordpress_abilities_api',
			'multisite_request_scope' => 'boot_blog_only',
			'client_claims_authoritative' => false,
			'host_refresh_confirmation_required' => true,
			'dynamic_projection_scope' => 'site_enrollment_explicit',
			'per_client_projection_isolation' => false,
			'authority_effect' => 'none',
			'mcp_fallback' => array(
				'resource' => class_exists( 'MAD4B_SCP_MCP_Client_Compatibility' ) ? MAD4B_SCP_MCP_Client_Compatibility::resource_identifier() : '',
				'discovery_tool' => class_exists( 'MAD4B_SCP_ChatGPT_Tool_Projection' ) ? MAD4B_SCP_ChatGPT_Tool_Projection::DISCOVER_ABILITY : 'mad4b/chatgpt-tool-projection-discover',
				'projection_plan_tool' => class_exists( 'MAD4B_SCP_ChatGPT_Tool_Projection' ) ? MAD4B_SCP_ChatGPT_Tool_Projection::PLAN_ABILITY : 'mad4b/chatgpt-tool-projection-plan',
				'projection_apply_tool' => class_exists( 'MAD4B_SCP_ChatGPT_Tool_Projection' ) ? MAD4B_SCP_ChatGPT_Tool_Projection::APPLY_ABILITY : 'mad4b/chatgpt-tool-projection-apply',
			),
		);
	}

	public static function register_rest_route() {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			array(
				'methods' => WP_REST_Server::CREATABLE,
				'callback' => array( __CLASS__, 'rest_callback' ),
				'permission_callback' => array( __CLASS__, 'can_read_rest' ),
			)
		);
	}

	public static function can_read_rest( $request = null ) {
		if ( ! self::runtime_blog_matches() ) return new WP_Error( 'mad4b_capability_gateway_blog_switch_denied', 'Use a fresh request to the target site.', array( 'status' => 409 ) );
		if ( is_object( $request ) && method_exists( $request, 'get_header' ) ) {
			return MAD4B_SCP_Ability_Catalog_Transport::rest_permission( $request );
		}
		if ( ! class_exists( 'MAD4B_SCP_Policy' ) ) return new WP_Error( 'mad4b_capability_gateway_policy_unavailable', 'Capability gateway policy is unavailable.', array( 'status' => 503 ) );
		$allowed = MAD4B_SCP_Policy::can_read();
		if ( is_wp_error( $allowed ) ) return $allowed;
		return $allowed ? true : new WP_Error( 'mad4b_capability_gateway_forbidden', 'Read authority is required for capability discovery.', array( 'status' => 403 ) );
	}

	public static function rest_callback( $request ) {
		$input = is_object( $request ) && method_exists( $request, 'get_json_params' ) ? $request->get_json_params() : array();
		if ( ! is_array( $input ) && is_object( $request ) && method_exists( $request, 'get_params' ) ) $input = $request->get_params();
		if ( ! is_array( $input ) ) $input = array();
		$result = self::dispatch( $input, 'rest' );
		if ( is_wp_error( $result ) ) return $result;
		$response = rest_ensure_response( $result );
		if ( $response instanceof WP_REST_Response ) {
			$response->header( 'Cache-Control', 'private, no-store' );
			$response->header( 'Vary', 'Authorization, Cookie' );
			$response->header( 'X-Content-Type-Options', 'nosniff' );
		}
		return $response;
	}

	public static function dispatch( array $input, $transport = 'internal' ) {
		if ( class_exists( 'MAD4B_SCP_Request_Generation' ) ) {
			$request_scope = MAD4B_SCP_Request_Generation::admit( 'capability_gateway' );
			if ( is_wp_error( $request_scope ) ) return $request_scope;
		}
		$valid = self::validate_input( $input );
		if ( is_wp_error( $valid ) ) return $valid;
		$permission = self::can_read_rest();
		if ( is_wp_error( $permission ) ) return $permission;
		$action = isset( $input['action'] ) ? sanitize_key( (string) $input['action'] ) : 'negotiate';
		if ( 'negotiate' === $action ) $result = self::negotiate( $input, $transport );
		elseif ( 'search' === $action ) {
			$call = static function() use ( $input, $transport ) { return self::search( $input, $transport ); };
			$result = class_exists( 'MAD4B_SCP_Observability' )
				? MAD4B_SCP_Observability::run_stage( 'discovery', $call, '', array( 'transport'=>$transport, 'gateway_action'=>'search' ) )
				: $call();
		}
		elseif ( 'sources' === $action || 'dependencies' === $action || 'recovery' === $action ) {
			// Keep one telemetry path for discovery, without adding background
			// work or scanning unrelated providers in the ordinary summary.
			$call = static function() use ( $action, $input ) {
				if ( 'sources' === $action ) return MAD4B_SCP_Capability_Source_Inventory::inspect( $input );
				if ( 'recovery' === $action ) return MAD4B_SCP_Recovery_Route_Resolver::inspect( $input );
				return MAD4B_SCP_Capability_Dependency_Planner::inspect( $input );
			};
			$result = class_exists( 'MAD4B_SCP_Observability' )
				? MAD4B_SCP_Observability::run_stage( 'discovery', $call, '',
					array( 'transport' => $transport, 'gateway_action' => $action ) )
				: $call();
		}
		elseif ( 'prepare' === $action ) {
			$call = static function() use ( $input, $transport ) { return self::prepare( $input, $transport ); };
			$result = class_exists( 'MAD4B_SCP_Observability' )
				? MAD4B_SCP_Observability::run_stage( 'preparation', $call, '', array( 'transport'=>$transport, 'gateway_action'=>'prepare' ) )
				: $call();
		}
		elseif ( in_array( $action, array( 'schema', 'chunk' ), true ) ) $result = self::schema_transport( $input, $action, $transport );
		else return new WP_Error( 'mad4b_capability_gateway_action_invalid', 'Unknown capability gateway action.' );
		if ( is_wp_error( $result ) ) return $result;
		$budget = self::client_capabilities( $input )['max_response_bytes'];
		if ( 'prepare' === $action && strlen( wp_json_encode( $result ) ) > $budget ) {
			foreach ( $result['abilities'] as &$entry ) {
				if ( ! isset( $entry['schema'] ) ) continue;
				unset( $entry['schema'] ); $entry['schema_transfer_mode'] = 'chunked';
				$entry['recommended_chunk_bytes'] = $result['transfer_policy']['recommended_chunk_bytes'];
				if ( strlen( wp_json_encode( $result ) ) <= $budget ) break;
			}
			unset( $entry );
		}
		if ( strlen( wp_json_encode( $result ) ) > $budget ) return new WP_Error( 'mad4b_capability_gateway_response_budget', 'Use a smaller batch or chunked schema transfer.', array( 'status' => 413 ) );
		return $result;
	}

	private static function validate_input( array $input ) {
		foreach ( array( 'action', 'task', 'query', 'ability_name', 'snapshot', 'schema_sha256', 'schema_format', 'cursor', 'category', 'surface', 'ability_prefix', 'match_mode', 'sort', 'operation', 'target_kind', 'source_id', 'goal', 'expected_head' ) as $key ) {
			if ( isset( $input[$key] ) && ( ! is_string( $input[$key] ) || strlen( $input[$key] ) > 4096 ) ) return new WP_Error( 'mad4b_capability_gateway_input_invalid', 'Gateway text fields must be bounded strings.', array( 'status' => 400 ) );
		}
		foreach ( array( 'ability_names' => self::MAX_PREPARE, 'keywords' => self::MAX_KEYWORDS, 'known_schemas' => self::MAX_PREPARE, 'blockers' => 16 ) as $key => $max ) {
			if ( ! isset( $input[$key] ) ) continue;
			if ( ! is_array( $input[$key] ) || count( $input[$key] ) > $max ) return new WP_Error( 'mad4b_capability_gateway_input_invalid', 'Gateway arrays exceed their preparation/search bounds.', array( 'status' => 400 ) );
			foreach ( $input[$key] as $value ) if ( ! is_string( $value ) || strlen( $value ) > 512 ) return new WP_Error( 'mad4b_capability_gateway_input_invalid', 'Gateway array entries must be bounded strings.', array( 'status' => 400 ) );
		}
		if ( array_key_exists( 'declared_readonly_only', $input ) && ! is_bool( $input['declared_readonly_only'] ) ) return new WP_Error( 'mad4b_capability_gateway_read_filter_invalid', 'Read-only discovery filter must be a boolean.', array( 'status' => 400 ) );
        if ( array_key_exists( 'declared_writeonly_only', $input ) && ! is_bool( $input['declared_writeonly_only'] ) ) return new WP_Error( 'mad4b_capability_gateway_write_filter_invalid', 'Mutation-only filter must be a boolean.', array( 'status' => 400 ) );
        if ( ! empty( $input['declared_writeonly_only'] ) && ! empty( $input['declared_readonly_only'] ) ) return new WP_Error( 'mad4b_capability_gateway_contradictory_filters', 'Cannot select both read-only and mutation-only metadata.', array( 'status' => 400 ) );
		if ( isset( $input['cursor'] ) && ( strlen( $input['cursor'] ) > 2048 || ! preg_match( '/^[A-Za-z0-9_-]+\\.[a-f0-9]{64}$/D', $input['cursor'] ) ) )
			return new WP_Error( 'mad4b_capability_gateway_cursor_invalid', 'Malformed capability search cursor.', array( 'status' => 400 ) );
		if ( isset( $input['match_mode'] ) && ! in_array( $input['match_mode'], array( 'any', 'all' ), true ) )
			return new WP_Error( 'mad4b_capability_gateway_match_mode_invalid', 'Search match mode must be any or all.', array( 'status' => 400 ) );
		if ( isset( $input['sort'] ) && ! in_array( $input['sort'], array( 'relevance', 'name' ), true ) )
			return new WP_Error( 'mad4b_capability_gateway_sort_invalid', 'Search sort must be relevance or name.', array( 'status' => 400 ) );
		if ( isset( $input['ability_prefix'] ) && '' !== $input['ability_prefix'] && ! preg_match( '#^[a-z0-9._/-]{1,160}$#D', $input['ability_prefix'] ) )
			return new WP_Error( 'mad4b_capability_gateway_prefix_invalid', 'Ability prefix is invalid.', array( 'status' => 400 ) );
		foreach ( array( 'category', 'surface' ) as $facet ) {
			if ( isset( $input[$facet] ) && '' !== $input[$facet] && ! preg_match( '/^[a-z0-9._-]{1,160}$/D', $input[$facet] ) )
				return new WP_Error( 'mad4b_capability_gateway_facet_invalid', 'Capability metadata facet is invalid.', array( 'status' => 400 ) );
		}
		if ( isset( $input['browse'] ) && ! is_bool( $input['browse'] ) )
			return new WP_Error( 'mad4b_capability_gateway_browse_invalid', 'Browse must be a boolean.', array( 'status' => 400 ) );
		if ( isset( $input['min_score'] ) && ( filter_var( $input['min_score'], FILTER_VALIDATE_INT ) === false || (int) $input['min_score'] < 0 || (int) $input['min_score'] > 10000 ) )
			return new WP_Error( 'mad4b_capability_gateway_score_invalid', 'Minimum score must be a bounded integer.', array( 'status' => 400 ) );
		if ( isset( $input['offset'] ) && ( filter_var( $input['offset'], FILTER_VALIDATE_INT ) === false || (int) $input['offset'] < 0 ) )
			return new WP_Error( 'mad4b_capability_gateway_offset_invalid', 'Offset must be a non-negative integer.', array( 'status' => 400 ) );
		if ( isset( $input['cursor'] ) && ! empty( $input['offset'] ) )
			return new WP_Error( 'mad4b_capability_gateway_pagination_conflict', 'Use cursor or offset, not both.', array( 'status' => 400 ) );
		if ( isset( $input['limit'] ) && false === filter_var( $input['limit'], FILTER_VALIDATE_INT ) ) return new WP_Error( 'mad4b_capability_gateway_input_invalid', 'Search limit must be an integer.', array( 'status' => 400 ) );
		if ( isset( $input['client_capabilities'] ) ) {
			if ( ! is_array( $input['client_capabilities'] ) || count( $input['client_capabilities'] ) > 16 ) return new WP_Error( 'mad4b_capability_gateway_input_invalid', 'Client capabilities must be a bounded object.', array( 'status' => 400 ) );
			foreach ( $input['client_capabilities'] as $value ) if ( ! is_scalar( $value ) || ( is_string( $value ) && strlen( $value ) > 80 ) ) return new WP_Error( 'mad4b_capability_gateway_input_invalid', 'Client capability values must be bounded scalars.', array( 'status' => 400 ) );
		}
		return true;
	}

	private static function as_bool( $value ) {
		if ( is_bool( $value ) ) return $value;
		if ( 1 === $value || '1' === $value ) return true;
		if ( is_string( $value ) ) return in_array( strtolower( trim( $value ) ), array( 'true', 'yes', 'on' ), true );
		return false;
	}

	private static function client_capabilities( array $input ) {
		$raw = isset( $input['client_capabilities'] ) && is_array( $input['client_capabilities'] ) ? $input['client_capabilities'] : array();
		$max_response = isset( $raw['max_response_bytes'] ) ? (int) $raw['max_response_bytes'] : self::DEFAULT_MAX_RESPONSE_BYTES;
		$max_response = max( 4096, min( 1048576, $max_response ) );
		$max_inline = isset( $raw['max_inline_schema_bytes'] ) ? (int) $raw['max_inline_schema_bytes'] : self::DEFAULT_INLINE_SCHEMA_BYTES;
		$max_inline = max( 1024, min( $max_response, $max_inline ) );
		$rtt = isset( $raw['observed_rtt_ms'] ) ? (int) $raw['observed_rtt_ms'] : 0;
		$rtt = max( 0, min( 60000, $rtt ) );
		$error_rate = isset( $raw['recent_error_rate'] ) && is_numeric( $raw['recent_error_rate'] ) ? (float) $raw['recent_error_rate'] : 0.0;
		$error_rate = max( 0.0, min( 1.0, $error_rate ) );
		// Conservative when the host has not advertised a safe parallel budget.
		$parallel = isset( $raw['max_parallel_schema_fetches'] ) ? (int) $raw['max_parallel_schema_fetches'] : 1;
		$parallel = max( 1, min( 8, $parallel ) );
		return array(
			'dynamic_tool_refresh' => self::as_bool( isset( $raw['dynamic_tool_refresh'] ) ? $raw['dynamic_tool_refresh'] : false ),
			'tools_list_changed' => self::as_bool( isset( $raw['tools_list_changed'] ) ? $raw['tools_list_changed'] : false ),
			'tools_list_pagination' => self::as_bool( isset( $raw['tools_list_pagination'] ) ? $raw['tools_list_pagination'] : false ),
			'max_response_bytes' => $max_response,
			'max_inline_schema_bytes' => $max_inline,
			'observed_rtt_ms' => $rtt,
			'recent_error_rate' => $error_rate,
			'max_parallel_schema_fetches' => $parallel,
		);
	}

	private static function transfer_policy( array $caps ) {
		$chunk = 32768;
		if ( $caps['recent_error_rate'] >= 0.05 || $caps['observed_rtt_ms'] >= 1200 ) $chunk = 16384;
		elseif ( $caps['observed_rtt_ms'] > 0 && $caps['observed_rtt_ms'] <= 250 && $caps['recent_error_rate'] < 0.01 ) $chunk = 131072;
		$chunk = min( $chunk, max( 1024, (int) ( ( $caps['max_response_bytes'] - 2048 ) * 3 / 4 ) ) );
		$chunk = max( 1024, min( 1048576, $chunk ) );
		// Unknown latency/error data must not invite concurrent full WordPress
		// boots. Clients may opt into higher parallelism after observing fast RTT.
		$parallel = 1;
		if ( $caps['recent_error_rate'] >= 0.05 || $caps['observed_rtt_ms'] >= 1200 ) $parallel = 1;
		elseif ( $caps['observed_rtt_ms'] > 0 && $caps['observed_rtt_ms'] <= 250 && $caps['recent_error_rate'] < 0.01 ) $parallel = 4;
		$parallel = min( $parallel, $caps['max_parallel_schema_fetches'] );
		return array(
			'recommended_chunk_bytes' => $chunk,
			'recommended_parallel_schema_fetches' => max( 1, $parallel ),
			'adaptive_inputs' => array( 'max_response_bytes', 'observed_rtt_ms', 'recent_error_rate', 'max_parallel_schema_fetches' ),
			'authority_effect' => 'none',
		);
	}

	private static function negotiation( array $input, $transport ) {
		$caps = self::client_capabilities( $input );
		$detected = class_exists( 'MAD4B_SCP_MCP_Client_Profile_Registry' ) ? MAD4B_SCP_MCP_Client_Profile_Registry::detect_request_profile() : array();
		$projection_permission = class_exists( 'MAD4B_SCP_ChatGPT_Tool_Projection' ) ? MAD4B_SCP_ChatGPT_Tool_Projection::can_apply() : new WP_Error( 'mad4b_projection_unavailable', 'Projection layer unavailable.' );
		$projection_authorized = true === $projection_permission;
		$dynamic_claimed = ! empty( $caps['dynamic_tool_refresh'] );
		$exposure = $dynamic_claimed && $projection_authorized ? 'dynamic_projection' : 'fixed_dispatch';
		$dynamic_state = ! $dynamic_claimed ? 'client_refresh_not_proven' : ( $projection_authorized ? 'ready' : 'governed_step_up_or_exact_client_required' );
		$rest_proven = 'rest' === $transport;
		return array(
			'contract' => self::CONTRACT,
			'client_capabilities' => $caps,
			'client_claims_authoritative' => false,
			'host_refresh_confirmation_required' => true,
			'detected_profile' => isset( $detected['profile'] ) ? $detected['profile'] : array(),
			'detection_authoritative' => false,
			'transport' => array(
				'catalog' => $rest_proven ? 'rest_gateway' : 'mcp_catalog_transport',
				'schema' => $rest_proven ? 'rest_gateway' : 'mcp_catalog_transport',
				'execution' => 'mcp_governed',
				'rest_gateway_proven_by_request' => $rest_proven,
				'mcp_resource' => class_exists( 'MAD4B_SCP_MCP_Client_Compatibility' ) ? MAD4B_SCP_MCP_Client_Compatibility::resource_identifier() : '',
			),
			'exposure_mode' => $exposure,
			'rest_auth_modes' => array( 'oauth_bearer', 'authenticated_wordpress_session' ),
			'remote_client_auth_mode' => 'oauth_bearer',
			'primary_execution_mode' => 'fixed_dispatch',
			'fixed_dispatch_preparation' => self::fixed_dispatch_preparation_contract(),
			'projection_role' => 'optional_hot_set',
			'server_tools_list_changed' => false,
			'refresh_strategy' => 'explicit_tools_list_or_reconnect',
			'dynamic_projection_state' => $dynamic_state,
			'dynamic_projection_scope' => 'site_enrollment_explicit',
			'per_client_projection_isolation' => false,
			'dynamic_projection_permission_blocker' => is_wp_error( $projection_permission ) ? $projection_permission->get_error_code() : '',
			'fixed_dispatch_tools' => array(
				'read' => 'mad4b/read-execute',
				'write' => 'mad4b/write-execute',
				'content' => 'mad4b/write-execute',
				'admin' => 'mad4b/write-execute',
				'developer' => 'mad4b/developer-execute',
				'enrollment' => 'mad4b/enrollment-execute',
			),
			'transfer_policy' => self::transfer_policy( $caps ),
			'source_of_truth' => 'wordpress_abilities_api',
			'multisite_request_scope' => 'boot_blog_only',
			'authority_effect' => 'none',
			'read_only' => true,
		);
	}

	public static function negotiate( array $input, $transport = 'internal' ) {
		return self::negotiation( $input, $transport );
	}

	private static function task_terms( array $input ) {
		$task = isset( $input['task'] ) ? trim( (string) $input['task'] ) : ( isset( $input['query'] ) ? trim( (string) $input['query'] ) : '' );
		$task = self::bounded_text( $task, self::MAX_TASK_BYTES );
		$terms = array();
		if ( '' !== $task ) {
			$split = preg_split( '/[^\p{L}\p{N}._+\/-]+/u', self::lower( $task ) );
			foreach ( is_array( $split ) ? $split : array() as $term ) if ( strlen( $term ) >= 2 ) $terms[] = $term;
		}
		$keywords = isset( $input['keywords'] ) && is_array( $input['keywords'] ) ? array_slice( $input['keywords'], 0, self::MAX_KEYWORDS ) : array();
		foreach ( $keywords as $keyword ) {
			$keyword = self::bounded_text( trim( (string) $keyword ), 80 );
			if ( '' !== $keyword ) $terms[] = self::lower( $keyword );
		}
		return array( $task, array_values( array_unique( $terms ) ) );
	}

	private static function lower( $value ) {
		$value = (string) $value;
		// Fold Arabic variants for discovery text only; never change a value
		// submitted to WordPress or an Ability name/authority signature.
		$folded = preg_replace( '/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $value );
		if ( is_string( $folded ) ) $value = strtr( $folded, array(
			'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا',
			'ى' => 'ي', 'ئ' => 'ي', 'ؤ' => 'و',
		) );
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
	}

	private static function bounded_text( $value, $max_bytes, $strip_tags = false ) {
		$value = (string) $value;
		$max_bytes = max( 1, (int) $max_bytes );
		if ( $strip_tags && function_exists( 'wp_strip_all_tags' ) ) $value = wp_strip_all_tags( $value );
		if ( strlen( $value ) <= $max_bytes ) return $value;
		if ( function_exists( 'mb_strcut' ) ) {
			$cut = mb_strcut( $value, 0, $max_bytes, 'UTF-8' );
			return is_string( $cut ) ? $cut : '';
		}
		$cut = substr( $value, 0, $max_bytes );
		return function_exists( 'wp_check_invalid_utf8' ) ? wp_check_invalid_utf8( $cut, true ) : $cut;
	}

	private static function relevance_score( array $row, $task, array $terms ) {
		$name = self::lower( isset( $row['ability_name'] ) ? $row['ability_name'] : '' );
		$label = self::lower( isset( $row['label'] ) ? $row['label'] : '' );
		$description = self::lower( isset( $row['description'] ) ? $row['description'] : '' );
		$category = self::lower( isset( $row['category'] ) ? $row['category'] : '' );
		$aliases = self::lower( implode( ' ', $row['search_aliases'] ?? array() ) );
		$score = 0;
		$phrase = self::lower( $task );
		if ( '' !== $phrase ) {
			if ( false !== strpos( $name, $phrase ) ) $score += 24;
			if ( false !== strpos( $label, $phrase ) ) $score += 16;
			if ( false !== strpos( $description, $phrase ) ) $score += 8;
			if ( false !== strpos( $aliases, $phrase ) ) $score += 12;
		}
		foreach ( $terms as $term ) {
			if ( false !== strpos( $name, $term ) ) $score += 8;
			if ( false !== strpos( $label, $term ) ) $score += 5;
			if ( false !== strpos( $description, $term ) ) $score += 2;
			if ( false !== strpos( $category, $term ) ) $score += 1;
			if ( false !== strpos( $aliases, $term ) ) $score += 3;
		}
		return $score;
	}


	/**
	 * Share the canonical catalog's authority identity (OAuth issuer/client/scopes,
	 * current caps, site, user) rather than inventing a weaker cursor scope.
	 * A cursor is a continuity token, never an authorization grant.
	 */
	private static function search_cursor_scope() {
		if ( ! class_exists( 'MAD4B_SCP_Ability_Catalog_Transport' )
			|| ! method_exists( 'MAD4B_SCP_Ability_Catalog_Transport', 'current_authority_scope' ) )
			return '';
		$authority = MAD4B_SCP_Ability_Catalog_Transport::current_authority_scope();
		if ( ! is_string( $authority ) || ! preg_match( '/^[a-f0-9]{64}$/D', $authority ) )
			return '';
		$profile = class_exists( 'MAD4B_SCP_Site_Profile' ) ? MAD4B_SCP_Site_Profile::status() : array();
		return hash( 'sha256', wp_json_encode( array(
			'authority_scope_sha256' => $authority,
			'profile_digest' => is_array( $profile ) ? (string) ( $profile['profile_digest'] ?? '' ) : '',
			'build' => defined( 'MAD4B_SCP_BOOT_PROVENANCE_SHA256' ) ? constant( 'MAD4B_SCP_BOOT_PROVENANCE_SHA256' ) : '',
		) ) );
	}

	private static function encode_search_cursor( $offset, $query_digest, $result_digest ) {
		if ( '' === self::search_cursor_scope() ) return '';
		$payload = array(
			'contract' => self::SEARCH_CURSOR_CONTRACT, 'scope' => self::search_cursor_scope(),
			'query_sha256' => $query_digest, 'results_sha256' => $result_digest,
			'offset' => (int) $offset, 'expires_at' => time() + self::SEARCH_CURSOR_TTL,
		);
		$encoded = rtrim( strtr( base64_encode( wp_json_encode( $payload ) ), '+/', '-_' ), '=' );
		return $encoded . '.' . hash_hmac( 'sha256', $encoded, wp_salt( 'auth' ) );
	}

	private static function decode_search_cursor( $cursor, $query_digest, $result_digest = null ) {
		if ( ! is_string( $cursor ) || strlen( $cursor ) > 2048 ||
			! preg_match( '/^[A-Za-z0-9_-]+\\.[a-f0-9]{64}$/D', $cursor ) )
			return new WP_Error( 'mad4b_capability_search_cursor_invalid', 'Search cursor is malformed.' );
		$parts = explode( '.', $cursor, 2 );
		if ( ! hash_equals( hash_hmac( 'sha256', $parts[0], wp_salt( 'auth' ) ), $parts[1] ) )
			return new WP_Error( 'mad4b_capability_search_cursor_invalid', 'Search cursor integrity failed.' );
		$raw = base64_decode( strtr( $parts[0], '-_', '+/' ), true );
		$data = is_string( $raw ) ? json_decode( $raw, true ) : null;
		if ( '' === self::search_cursor_scope() ) return new WP_Error( 'mad4b_capability_search_scope_unavailable', 'Canonical catalog authority scope is unavailable.' );
		if ( ! is_array( $data ) || self::SEARCH_CURSOR_CONTRACT !== ( $data['contract'] ?? '' ) ||
			! hash_equals( self::search_cursor_scope(), (string) ( $data['scope'] ?? '' ) ) ||
			! hash_equals( $query_digest, (string) ( $data['query_sha256'] ?? '' ) ) ||
			( null !== $result_digest && ! hash_equals( $result_digest, (string) ( $data['results_sha256'] ?? '' ) ) ) ||
			! is_int( $data['offset'] ?? null ) || $data['offset'] < 0 ||
			! is_int( $data['expires_at'] ?? null ) || $data['expires_at'] <= time() )
			return new WP_Error( 'mad4b_capability_search_cursor_stale', 'Search identity or results changed; restart the query.', array( 'status' => 409 ) );
		return $data['offset'];
	}

	public static function search( array $input, $transport = 'internal' ) {
		// Internal callers bypass dispatch(), so validate every cursor and structural facet here too.
		$valid = self::validate_input( $input );
		if ( is_wp_error( $valid ) ) return $valid;
		if ( '' === self::search_cursor_scope() )
			return new WP_Error( 'mad4b_capability_search_scope_unavailable', 'Canonical catalog authority scope is unavailable.' );
		list( $task, $terms ) = self::task_terms( $input );
		$browse = true === ( $input['browse'] ?? false );
		if ( '' === $task && empty( $terms ) && ! $browse )
			return new WP_Error( 'mad4b_capability_gateway_task_required', 'Task or explicit browse=true is required.' );
		$limit = isset( $input['limit'] ) ? max( 1, min( 25, (int) $input['limit'] ) ) : 12;
		$readonly_only = true === ( $input['declared_readonly_only'] ?? false );
		$writeonly_only = true === ( $input['declared_writeonly_only'] ?? false );
		$category = (string) ( $input['category'] ?? '' );
		$surface = (string) ( $input['surface'] ?? '' );
		$prefix = (string) ( $input['ability_prefix'] ?? '' );
		$mode = (string) ( $input['match_mode'] ?? 'any' );
		$sort = (string) ( $input['sort'] ?? 'relevance' );
		$min_score = isset( $input['min_score'] ) ? (int) $input['min_score'] : 0;
		$facets = array( 'category' => $category, 'surface' => $surface,
			'ability_prefix' => $prefix, 'declared_readonly_only' => $readonly_only,
			'declared_writeonly_only' => $writeonly_only, 'match_mode' => $mode,
			'sort' => $sort, 'min_score' => $min_score, 'browse' => $browse );
		$query_digest = hash( 'sha256', wp_json_encode( array( $task, $terms, $facets ) ) );
		// Fail on cross-user/OAuth/site or expired cursors before any cache read.
		// Snapshot integrity is still rechecked after ranking the current results.
		if ( ! empty( $input['cursor'] ) ) {
			$cursor_preflight = self::decode_search_cursor( $input['cursor'], $query_digest );
			if ( is_wp_error( $cursor_preflight ) ) return $cursor_preflight;
		}
		if ( ! class_exists( 'MAD4B_SCP_Capability_Search_Index' ) )
			return new WP_Error( 'mad4b_capability_index_unavailable', 'Capability index must be loaded by the plugin bootstrap.' );
		$scope = self::search_cursor_scope();
		$ability_names = MAD4B_SCP_ChatGPT_Tool_Projection::all_site_ability_names();
		$catalog = MAD4B_SCP_Capability_Search_Index::catalog( $ability_names, $scope );
		if ( is_wp_error( $catalog ) ) return $catalog;
		$ranked = MAD4B_SCP_Capability_Search_Index::results(
			$catalog, $scope, $query_digest,
			static function ( array $rows ) use ( $readonly_only, $writeonly_only, $category,
				$surface, $prefix, $task, $terms, $mode, $browse, $min_score, $sort ) {
				$matches = array();
				foreach ( $rows as $row ) {
					if ( $readonly_only && true !== $row['declared_readonly'] ) continue;
					if ( $writeonly_only && false !== $row['declared_readonly'] ) continue;
					if ( '' !== $category && $category !== sanitize_key( $row['category'] ) ) continue;
					if ( '' !== $surface && $surface !== $row['declared_surface'] ) continue;
					if ( '' !== $prefix && 0 !== strpos( $row['ability_name'], $prefix ) ) continue;
					$score = self::relevance_score( $row, $task, $terms );
					if ( 'all' === $mode && $terms ) {
						$all_found = true;
						foreach ( $terms as $term ) {
							if ( self::relevance_score( $row, '', array( $term ) ) > 0 ) continue;
							$all_found = false; break;
						}
						if ( ! $all_found ) continue;
					}
					if ( $score <= 0 && ! ( $browse && '' === $task && ! $terms ) ) continue;
					if ( $score < $min_score ) continue;
					$row['preparation_required'] = true;
					$row['schema_loaded'] = false;
					$row['authority_decision_deferred'] = true;
					$row['relevance_score'] = $score;
					$matches[] = $row;
				}
				usort( $matches, static function ( $a, $b ) use ( $sort ) {
					if ( 'name' === $sort ) return strcmp( $a['ability_name'], $b['ability_name'] );
					if ( $a['relevance_score'] === $b['relevance_score'] ) return strcmp( $a['ability_name'], $b['ability_name'] );
					return $a['relevance_score'] > $b['relevance_score'] ? -1 : 1;
				} );
				return $matches;
			}
		);
		if ( is_wp_error( $ranked ) ) return $ranked;
		$matches = $ranked['matches'];
		$total = count( $matches );
		$result_digest = $ranked['digest'];
		$offset = isset( $input['offset'] ) ? (int) $input['offset'] : 0;
		if ( ! empty( $input['cursor'] ) ) {
			$decoded = self::decode_search_cursor( $input['cursor'], $query_digest, $result_digest );
			if ( is_wp_error( $decoded ) ) return $decoded;
			$offset = $decoded;
		}
		if ( $offset > $total ) return new WP_Error( 'mad4b_capability_search_offset_stale', 'Search offset is beyond current results; restart.', array( 'status' => 409 ) );
		$page = array_slice( $matches, $offset, $limit );
		$next_offset = $offset + count( $page );
		$next_cursor = $next_offset < $total ? self::encode_search_cursor( $next_offset, $query_digest, $result_digest ) : null;
		$negotiation = self::negotiation( $input, $transport );
		return array(
			'contract' => self::CONTRACT,
			'task' => $task, 'keywords' => $terms,
			'search_mode' => $readonly_only ? 'bounded_declared_readonly_metadata_relevance'
				: ( $writeonly_only ? 'bounded_declared_mutation_metadata_relevance' : 'bounded_metadata_only_relevance' ),
			'declared_readonly_filter_applied' => $readonly_only,
			'declared_writeonly_filter_applied' => $writeonly_only,
			'facets_applied' => $facets,
			'items' => $page, 'count' => count( $page ),
			'total_match_count' => $total, 'page_offset' => $offset, 'page_limit' => $limit,
			'next_cursor' => $next_cursor, 'has_more' => null !== $next_cursor,
			'search_snapshot_sha256' => $result_digest,
			'metadata_snapshot_sha256' => $catalog['snapshot_sha256'],
			'index_contract' => MAD4B_SCP_Capability_Search_Index::CONTRACT,
			'coverage_state' => $catalog['coverage_state'],
			'scanned_count' => $catalog['scanned_count'],
			'skipped_count' => $catalog['skipped_count'],
			'catalog_cache_hit' => ! empty( $catalog['cache_hit'] ),
			'results_cache_hit' => ! empty( $ranked['cache_hit'] ),
			'catalog_cache_persisted' => ! empty( $catalog['cache_persisted'] ),
			'results_cache_persisted' => ! empty( $ranked['cache_persisted'] ),
			'universe_count' => count( $ability_names ),
			'preparation_required_for_authority_and_schema' => true,
			'transport_strategy' => $negotiation['transport'],
			'authority_effect' => 'none', 'read_only' => true,
		);
	}

	private static function execution_descriptor( array $row ) {
		$lane = isset( $row['execution_lane'] ) ? (string) $row['execution_lane'] : '';
		$ability_name = isset( $row['ability_name'] ) ? (string) $row['ability_name'] : '';
		$input_schema_sha256 = isset( $row['input_schema_sha256'] ) ? (string) $row['input_schema_sha256'] : '';
		$execution_blocker = isset( $row['execution_blocker'] ) ? (string) $row['execution_blocker'] : '';
		if ( empty( $row['execution_eligible'] ) || '' !== $execution_blocker ) {
			return array(
				'state' => 'blocked',
				'blocker' => '' !== $execution_blocker ? $execution_blocker : 'no_governed_dispatch_lane',
				'transport' => 'none',
			);
		}
		if ( in_array( $lane, array( 'write', 'content', 'admin' ), true ) && ( ! isset( $row['readonly'] ) || false !== $row['readonly'] ) ) return array( 'state' => 'blocked', 'blocker' => 'write_dispatch_requires_mutation_annotation', 'transport' => 'none' );
		if ( 'enrollment' === $lane ) {
			$resolved_id = class_exists( 'MAD4B_SCP_Governed_Ability_Census' )
				? MAD4B_SCP_Governed_Ability_Census::operation_id_for( $ability_name ) : '';
			return array(
				'state' => 'requires_operation_resolution',
				'resolved_operation_id' => $resolved_id,
				'registry_verified_first_party' => '' !== $resolved_id,
				'blocker' => 'enrollment_operation_resolution_required',
				'transport' => 'mcp',
				'server' => 'mad4b-chatgpt',
				'discovery_tool' => 'mad4b/enrollment-discover',
				'info_tool' => 'mad4b/enrollment-info',
				'dispatch_tool' => 'mad4b/enrollment-execute',
				'target_ability' => $ability_name,
				'direct_ability_dispatch' => false,
				'revalidation' => array( 'authority', 'site_binding', 'registration_digest', 'dispatch_policy_digest', 'input_schema_identity' ),
			);
		}
		if ( in_array( $lane, array( 'write', 'content', 'admin' ), true ) && ( ! class_exists( 'MAD4B_SCP_Servers' ) || ! MAD4B_SCP_Servers::ability_is_mounted( 'mad4b-write', $ability_name ) ) ) {
			return array(
				'state' => 'blocked',
				'blocker' => 'write_runtime_not_eligible',
				'transport' => 'none',
			);
		}
		if ( 'developer' === $lane && ( ! class_exists( 'MAD4B_SCP_Servers' ) || ! MAD4B_SCP_Servers::ability_is_mounted( 'mad4b-developer', $ability_name ) ) ) {
			return array(
				'state' => 'blocked',
				'blocker' => 'developer_target_not_mounted',
				'transport' => 'none',
			);
		}
		$map = array(
			'read' => 'mad4b/read-execute',
			'write' => 'mad4b/write-execute',
			'content' => 'mad4b/write-execute',
			'admin' => 'mad4b/write-execute',
			'developer' => 'mad4b/developer-execute',
		);
		if ( ! isset( $map[ $lane ] ) ) {
			return array(
				'state' => 'blocked',
				'blocker' => 'no_governed_dispatch_lane',
				'transport' => 'none',
			);
		}
		return array(
			'state' => 'governed_dispatch',
			'transport' => 'mcp',
			'server' => 'mad4b-chatgpt',
			'dispatch_tool' => $map[ $lane ],
			'target_ability' => $ability_name,
			'expected_input_schema_sha256' => $input_schema_sha256,
			'expected_execution_lane' => $lane,
			'expected_classification_sha256' => isset( $row['classification_sha256'] ) ? (string) $row['classification_sha256'] : '',
			'direct_ability_dispatch' => true,
			'revalidation' => array( 'authority', 'site_binding', 'input_schema_identity', 'classification_identity', 'original_execution_lane' ),
		);
	}

	public static function describe_execution( array $row ) {
		return self::execution_descriptor( $row );
	}

	public static function prepare( array $input, $transport = 'internal' ) {
		$names = isset( $input['ability_names'] ) && is_array( $input['ability_names'] ) ? array_values( array_unique( array_map( 'strval', $input['ability_names'] ) ) ) : array();
		$names = array_values( array_filter( $names, static function ( $name ) { return '' !== trim( (string) $name ); } ) );
		if ( empty( $names ) ) return new WP_Error( 'mad4b_capability_gateway_abilities_required', 'At least one Ability name is required for preparation.' );
		if ( count( $names ) > self::MAX_PREPARE ) return new WP_Error( 'mad4b_capability_gateway_prepare_too_large', 'Too many Abilities requested for one preparation batch.' );
		sort( $names, SORT_STRING );
		$caps = self::client_capabilities( $input );
		$transfer = self::transfer_policy( $caps );
		$known = isset( $input['known_schemas'] ) && is_array( $input['known_schemas'] ) ? array_slice( $input['known_schemas'], 0, self::MAX_PREPARE, true ) : array();
		$items = array();
		foreach ( $names as $ability_name ) {
			$row = MAD4B_SCP_Capability_Descriptor_Registry::describe( $ability_name );
			if ( is_wp_error( $row ) ) {
				$items[] = array( 'ability_name' => $ability_name, 'state' => 'unavailable', 'blocker' => $row->get_error_code() );
				continue;
			}
			$prepared = MAD4B_SCP_Ability_Catalog_Transport::prepare_ability( $ability_name );
			if ( is_wp_error( $prepared ) ) {
				$items[] = array( 'ability_name' => $ability_name, 'state' => 'schema_unavailable', 'blocker' => $prepared->get_error_code(), 'execution' => self::execution_descriptor( $row ) );
				continue;
			}
			$item = $prepared['item'];
			$digest = isset( $item['schema_sha256'] ) ? (string) $item['schema_sha256'] : '';
			$known_digest = isset( $known[ $ability_name ] ) ? strtolower( trim( (string) $known[ $ability_name ] ) ) : '';
			$reusable = 64 === strlen( $known_digest ) && hash_equals( strtolower( $digest ), $known_digest );
			$schema_mode = $reusable ? 'cached' : ( (int) $item['schema_bytes'] <= $caps['max_inline_schema_bytes'] ? 'inline' : 'chunked' );
			$entry = array(
				'ability_name' => $ability_name,
				'classification' => isset( $row['classification'] ) ? (string) $row['classification'] : 'unavailable',
				'projection_eligible' => ! empty( $row['projection_eligible'] ),
				'execution_eligible' => ! empty( $row['execution_eligible'] ),
				'projection_blockers' => isset( $row['projection_blockers'] ) && is_array( $row['projection_blockers'] ) ? $row['projection_blockers'] : array(),
				'schema_sha256' => $digest,
				'schema_bytes' => (int) $item['schema_bytes'],
				'source' => $item['source'],
				'wire' => $item['wire'] ?? null,
				'authority_scope_sha256' => $prepared['authority_scope_sha256'],
				'input_schema_sha256' => $row['input_schema_sha256'],
				'classification_sha256' => $row['classification_sha256'],
				'schema_cache_state' => $reusable ? 'reusable' : 'refresh_required',
				'schema_transfer_mode' => $schema_mode,
				'snapshot' => (string) $prepared['snapshot'],
				'expires_at' => (int) $prepared['expires_at'],
				'execution' => self::execution_descriptor( $row ),
			);
			if ( class_exists( 'MAD4B_SCP_Preparation_Receipt' ) ) {
				$preparation_receipt = MAD4B_SCP_Preparation_Receipt::issue( $row );
				$entry['descriptor_sha256'] = $row['descriptor_sha256'] ?? '';
				$entry['generation_roots'] = $row['generation_roots'] ?? array();
				if ( is_wp_error( $preparation_receipt ) || ! is_string( $preparation_receipt ) || '' === $preparation_receipt ) {
					$entry['preparation_receipt'] = '';
					$entry['preparation_receipt_ready'] = false;
					$entry['preparation_receipt_blocker'] = is_wp_error( $preparation_receipt ) ? sanitize_key( (string) $preparation_receipt->get_error_code() ) : 'preparation_receipt_unavailable';
					$entry['execution_eligible'] = false;
				} else {
					$entry['preparation_receipt'] = $preparation_receipt;
					$entry['preparation_receipt_ready'] = true;
					$entry['preparation_receipt_blocker'] = '';
				}
			}
			if ( ! $reusable && 'inline' === $schema_mode && strlen( wp_json_encode( $items ) ) + (int) $item['schema_bytes'] + 4096 < $caps['max_response_bytes'] ) {
				$schema = MAD4B_SCP_Ability_Catalog_Transport::handle( array( 'transport_action' => 'schema', 'snapshot' => $prepared['snapshot'], 'schema_sha256' => $digest ) );
				if ( ! is_wp_error( $schema ) && isset( $schema['schema'] ) ) $entry['schema'] = $schema['schema'];
			}
			if ( ! $reusable && ! isset( $entry['schema'] ) ) $entry['schema_transfer_mode'] = 'chunked';
			if ( 'chunked' === $entry['schema_transfer_mode'] ) $entry['recommended_chunk_bytes'] = $transfer['recommended_chunk_bytes'];
			$items[] = $entry;
		}
		$projection_plan = MAD4B_SCP_ChatGPT_Tool_Projection::plan( array( 'mode' => 'replace', 'ability_names' => $names, 'include_breakglass' => false ) );
		$negotiation = self::negotiation( $input, $transport );
		$plan_ready = ! is_wp_error( $projection_plan ) && ! empty( $projection_plan['ready_for_apply'] );
		$dynamic_ready = 'dynamic_projection' === $negotiation['exposure_mode'] && $plan_ready;
		return array(
			'contract' => self::CONTRACT,
			'abilities' => $items,
			'count' => count( $items ),
			'exposure' => array(
				'mode' => $dynamic_ready ? 'dynamic_projection' : 'fixed_dispatch',
				'dynamic_projection_state' => $negotiation['dynamic_projection_state'],
				'projection_plan_ready' => $plan_ready,
				'projection_plan_sha256' => $plan_ready ? (string) $projection_plan['plan_sha256'] : '',
				'projection_plan_blocker' => is_wp_error( $projection_plan ) ? $projection_plan->get_error_code() : ( $plan_ready ? '' : 'projection_preflight_not_ready' ),
				'projection_is_never_implicit' => true,
				'projection_scope' => 'site_enrollment_explicit',
				'per_client_projection_isolation' => false,
			),
			'transport' => $negotiation['transport'],
			'transfer_policy' => $transfer,
			'client_claims_authoritative' => false,
			'host_refresh_confirmation_required' => true,
			'authority_effect' => 'none',
			'read_only' => true,
		);
	}

	private static function schema_transport( array $input, $action, $transport ) {
		$caps = self::client_capabilities( $input );
		if ( ( empty( $input['snapshot'] ) || empty( $input['schema_sha256'] ) ) && ! empty( $input['ability_name'] ) ) {
			$prepared = MAD4B_SCP_Ability_Catalog_Transport::prepare_ability( (string) $input['ability_name'] );
			if ( is_wp_error( $prepared ) ) return $prepared;
			$format = isset( $input['schema_format'] ) ? sanitize_key( (string) $input['schema_format'] ) : 'source';
			$descriptor = 'wire' === $format
				? ( isset( $prepared['item']['wire'] ) && is_array( $prepared['item']['wire'] ) ? $prepared['item']['wire'] : array() )
				: ( isset( $prepared['item']['source'] ) && is_array( $prepared['item']['source'] ) ? $prepared['item']['source'] : array() );
			if ( empty( $descriptor['sha256'] ) ) return new WP_Error( 'mad4b_capability_gateway_schema_format_unavailable', 'Requested Ability schema format is unavailable.' );
			$input['snapshot'] = $prepared['snapshot'];
			$input['schema_sha256'] = (string) $descriptor['sha256'];
			$input['schema_format'] = $format;
		}
		$input['transport_action'] = $action;
		// Chunk indices are meaningful only with the caller's pinned size. A
		// recommendation may choose a default, but must never rewrite that size.
		if ( 'chunk' === $action && ! isset( $input['chunk_bytes'] ) ) $input['chunk_bytes'] = self::transfer_policy( $caps )['recommended_chunk_bytes'];
		$result = MAD4B_SCP_Ability_Catalog_Transport::handle( $input );
		if ( is_wp_error( $result ) ) return $result;
		$result['gateway_contract'] = self::CONTRACT;
		$result['transport'] = 'rest' === $transport ? 'rest_gateway' : 'mcp_catalog_transport';
		$result['authority_effect'] = 'none';
		return $result;
	}
}
MAD4B_SCP_Unified_Capability_Gateway::boot();
