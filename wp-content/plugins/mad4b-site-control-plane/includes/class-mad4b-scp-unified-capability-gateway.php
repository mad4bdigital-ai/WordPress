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
	const DEFAULT_INLINE_SCHEMA_BYTES = 65536;
	const DEFAULT_MAX_RESPONSE_BYTES = 131072;

	private static $booted = false;

	public static function boot() {
		if ( self::$booted || ! function_exists( 'add_action' ) ) return;
		self::$booted = true;
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_route' ), 2 );
	}

	public static function rest_url() {
		return untrailingslashit( rest_url( self::REST_NAMESPACE . self::REST_ROUTE ) );
	}

	public static function public_manifest() {
		return array(
			'contract' => self::CONTRACT,
			'rest_url' => self::rest_url(),
			'rest_requires_oauth_bearer' => true,
			'actions' => array( 'negotiate', 'search', 'prepare', 'schema', 'chunk' ),
			'source_of_truth' => 'wordpress_abilities_api',
			'client_claims_authoritative' => false,
			'host_refresh_confirmation_required' => true,
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
		unset( $request );
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
		if ( $response instanceof WP_REST_Response ) $response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	public static function dispatch( array $input, $transport = 'internal' ) {
		$permission = self::can_read_rest();
		if ( is_wp_error( $permission ) ) return $permission;
		$action = isset( $input['action'] ) ? sanitize_key( (string) $input['action'] ) : 'negotiate';
		if ( 'negotiate' === $action ) $result = self::negotiate( $input, $transport );
		elseif ( 'search' === $action ) $result = self::search( $input, $transport );
		elseif ( 'prepare' === $action ) $result = self::prepare( $input, $transport );
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
		$parallel = isset( $raw['max_parallel_schema_fetches'] ) ? (int) $raw['max_parallel_schema_fetches'] : 4;
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
		$parallel = 2;
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
			'dynamic_projection_state' => $dynamic_state,
			'dynamic_projection_permission_blocker' => is_wp_error( $projection_permission ) ? $projection_permission->get_error_code() : '',
			'fixed_dispatch_tools' => array(
				'read' => 'mad4b/read-execute',
				'write' => 'mad4b/write-execute',
				'developer' => 'mad4b/developer-execute',
				'enrollment' => 'mad4b/enrollment-execute',
			),
			'transfer_policy' => self::transfer_policy( $caps ),
			'source_of_truth' => 'wordpress_abilities_api',
			'authority_effect' => 'none',
			'read_only' => true,
		);
	}

	public static function negotiate( array $input, $transport = 'internal' ) {
		return self::negotiation( $input, $transport );
	}

	private static function task_terms( array $input ) {
		$task = isset( $input['task'] ) ? trim( (string) $input['task'] ) : ( isset( $input['query'] ) ? trim( (string) $input['query'] ) : '' );
		if ( strlen( $task ) > self::MAX_TASK_BYTES ) $task = substr( $task, 0, self::MAX_TASK_BYTES );
		$terms = array();
		if ( '' !== $task ) {
			$split = preg_split( '/[^\p{L}\p{N}._+\/-]+/u', function_exists( 'mb_strtolower' ) ? mb_strtolower( $task, 'UTF-8' ) : strtolower( $task ) );
			foreach ( is_array( $split ) ? $split : array() as $term ) if ( strlen( $term ) >= 2 ) $terms[] = $term;
		}
		$keywords = isset( $input['keywords'] ) && is_array( $input['keywords'] ) ? array_slice( $input['keywords'], 0, self::MAX_KEYWORDS ) : array();
		foreach ( $keywords as $keyword ) {
			$keyword = trim( (string) $keyword );
			if ( '' !== $keyword && strlen( $keyword ) <= 80 ) $terms[] = function_exists( 'mb_strtolower' ) ? mb_strtolower( $keyword, 'UTF-8' ) : strtolower( $keyword );
		}
		return array( $task, array_values( array_unique( $terms ) ) );
	}

	private static function lower( $value ) {
		$value = (string) $value;
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
	}

	private static function relevance_score( array $row, $task, array $terms ) {
		$name = self::lower( isset( $row['ability_name'] ) ? $row['ability_name'] : '' );
		$label = self::lower( isset( $row['label'] ) ? $row['label'] : '' );
		$description = self::lower( isset( $row['description'] ) ? $row['description'] : '' );
		$category = self::lower( isset( $row['category'] ) ? $row['category'] : '' );
		$score = 0;
		$phrase = self::lower( $task );
		if ( '' !== $phrase ) {
			if ( false !== strpos( $name, $phrase ) ) $score += 24;
			if ( false !== strpos( $label, $phrase ) ) $score += 16;
			if ( false !== strpos( $description, $phrase ) ) $score += 8;
		}
		foreach ( $terms as $term ) {
			if ( false !== strpos( $name, $term ) ) $score += 8;
			if ( false !== strpos( $label, $term ) ) $score += 5;
			if ( false !== strpos( $description, $term ) ) $score += 2;
			if ( false !== strpos( $category, $term ) ) $score += 1;
		}
		return $score;
	}

	public static function search( array $input, $transport = 'internal' ) {
		list( $task, $terms ) = self::task_terms( $input );
		if ( '' === $task && empty( $terms ) ) return new WP_Error( 'mad4b_capability_gateway_task_required', 'Task text or keywords are required for bounded capability search.' );
		$limit = isset( $input['limit'] ) ? max( 1, min( 25, (int) $input['limit'] ) ) : 12;
		$matches = array();
		foreach ( MAD4B_SCP_ChatGPT_Tool_Projection::all_site_ability_names() as $ability_name ) {
			$row = MAD4B_SCP_ChatGPT_Tool_Projection::describe_ability( $ability_name );
			if ( is_wp_error( $row ) ) continue;
			$score = self::relevance_score( $row, $task, $terms );
			if ( $score <= 0 ) continue;
			$matches[] = array(
				'ability_name' => $ability_name,
				'label' => isset( $row['label'] ) ? (string) $row['label'] : '',
				'description' => isset( $row['description'] ) ? substr( wp_strip_all_tags( (string) $row['description'] ), 0, 320 ) : '',
				'category' => isset( $row['category'] ) ? (string) $row['category'] : '',
				'classification' => isset( $row['classification'] ) ? (string) $row['classification'] : 'unavailable',
				'readonly' => ! empty( $row['readonly'] ),
				'projection_eligible' => ! empty( $row['projection_eligible'] ),
				'execution_eligible' => ! empty( $row['execution_eligible'] ),
				'input_schema_sha256' => isset( $row['input_schema_sha256'] ) ? (string) $row['input_schema_sha256'] : '',
				'schema_reference_requires_prepare' => true,
				'classification_sha256' => isset( $row['classification_sha256'] ) ? (string) $row['classification_sha256'] : '',
				'projection_blockers' => isset( $row['projection_blockers'] ) && is_array( $row['projection_blockers'] ) ? $row['projection_blockers'] : array(),
				'relevance_score' => $score,
			);
		}
		usort( $matches, static function ( $a, $b ) {
			if ( $a['relevance_score'] === $b['relevance_score'] ) return strcmp( $a['ability_name'], $b['ability_name'] );
			return $a['relevance_score'] > $b['relevance_score'] ? -1 : 1;
		} );
		$matches = array_slice( $matches, 0, $limit );
		$negotiation = self::negotiation( $input, $transport );
		return array(
			'contract' => self::CONTRACT,
			'task' => $task,
			'keywords' => $terms,
			'search_mode' => 'bounded_lexical_relevance',
			'items' => $matches,
			'count' => count( $matches ),
			'universe_count' => count( MAD4B_SCP_ChatGPT_Tool_Projection::all_site_ability_names() ),
			'transport_strategy' => $negotiation['transport'],
			'authority_effect' => 'none',
			'read_only' => true,
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
		if ( 'enrollment' === $lane ) {
			return array(
				'state' => 'requires_operation_resolution',
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
		if ( 'read' === $lane && ( ! class_exists( 'MAD4B_SCP_Servers' ) || ! MAD4B_SCP_Servers::is_chatgpt_full_catalog_candidate( $ability_name ) ) ) {
			return array(
				'state' => 'requires_dynamic_projection',
				'blocker' => 'read_target_not_in_fixed_dispatch_catalog',
				'transport' => 'mcp_dynamic_projection',
				'target_ability' => $ability_name,
				'expected_input_schema_sha256' => $input_schema_sha256,
				'direct_ability_dispatch' => false,
			);
		}
		if ( 'write' === $lane && ( ! class_exists( 'MAD4B_SCP_Servers' ) || ! MAD4B_SCP_Servers::ability_is_mounted( 'mad4b-write', $ability_name ) ) ) {
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
			'direct_ability_dispatch' => true,
			'revalidation' => array( 'authority', 'site_binding', 'input_schema_identity', 'classification_identity', 'original_execution_lane' ),
		);
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
			$row = MAD4B_SCP_ChatGPT_Tool_Projection::describe_ability( $ability_name );
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
			$input['snapshot'] = $prepared['snapshot'];
			$input['schema_sha256'] = $prepared['item']['schema_sha256'];
		}
		$input['transport_action'] = $action;
		if ( 'chunk' === $action ) $input['chunk_bytes'] = min( (int) ( $input['chunk_bytes'] ?? 1048576 ), self::transfer_policy( $caps )['recommended_chunk_bytes'] );
		$result = MAD4B_SCP_Ability_Catalog_Transport::handle( $input );
		if ( is_wp_error( $result ) ) return $result;
		$result['gateway_contract'] = self::CONTRACT;
		$result['transport'] = 'rest' === $transport ? 'rest_gateway' : 'mcp_catalog_transport';
		$result['authority_effect'] = 'none';
		return $result;
	}
}
MAD4B_SCP_Unified_Capability_Gateway::boot();

