<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Internal JetEngine native-tool client.
 *
 * JetEngine 3.8+ can expose the same provider-native feature set through two
 * first-party transports: the MCP JSON-RPC endpoint and the WordPress REST
 * mcp-tools registry/run endpoints. MAD4B prefers MCP when it is enabled, but
 * may fall back to the native REST registry without creating credentials,
 * enabling provider settings or touching provider storage directly.
 *
 * Both paths are discovery-first and fail-closed: route ownership, exact tool
 * name, input-schema identity and native input are resolved before execution.
 */
final class MAD4B_SCP_JetEngine_MCP_Client {
	const CONTRACT = 'mad4b.jetengine-mcp-client.v4';
	const PROTOCOL_VERSION = '2025-06-18';
	const CLIENT_NAME = 'mad4b-site-control-plane';

	private static $endpoint = null;
	private static $registry_endpoint = null;
	private static $run_route_available = null;
	private static $tools = null;

	/**
	 * Return the already-created REST server only after WordPress has entered the
	 * REST registration lifecycle. Provider discovery must never call
	 * rest_get_server() itself: doing so before the MCP Adapter has attached its
	 * rest_api_init callbacks consumes that lifecycle too early and leaves the
	 * governed MCP servers registered without REST routes.
	 */
	private static function initialized_rest_server() {
		if ( ! function_exists( 'did_action' ) || did_action( 'rest_api_init' ) < 1 ) return null;
		global $wp_rest_server;
		return is_object( $wp_rest_server ) && method_exists( $wp_rest_server, 'get_routes' ) ? $wp_rest_server : null;
	}

	private static function routes() {
		$server = self::initialized_rest_server();
		if ( ! $server ) return array();
		$routes = $server->get_routes();
		return is_array( $routes ) ? $routes : array();
	}

	private static function isolated_transport_status() {
		if ( ! class_exists( 'MAD4B_SCP_MCP_Provider_Isolation' ) || ! method_exists( 'MAD4B_SCP_MCP_Provider_Isolation', 'internal_provider_transport_status' ) ) {
			return array( 'registry_available' => false, 'run_available' => false, 'retained_route_count' => 0, 'raw_routes_exposed' => false );
		}
		$status = MAD4B_SCP_MCP_Provider_Isolation::internal_provider_transport_status( 'jetengine' );
		return is_array( $status ) ? $status : array( 'registry_available' => false, 'run_available' => false, 'retained_route_count' => 0, 'raw_routes_exposed' => false );
	}

	private static function isolated_rest_tools_available() {
		$status = self::isolated_transport_status();
		return ! empty( $status['registry_available'] ) && ! empty( $status['run_available'] );
	}

	private static function dispatch_isolated_request( WP_REST_Request $request ) {
		if ( ! class_exists( 'MAD4B_SCP_MCP_Provider_Isolation' ) || ! method_exists( 'MAD4B_SCP_MCP_Provider_Isolation', 'dispatch_internal_provider_request' ) ) {
			return new WP_Error( 'mad4b_jetengine_isolated_transport_unavailable', 'JetEngine isolated provider handoff is unavailable.' );
		}
		return MAD4B_SCP_MCP_Provider_Isolation::dispatch_internal_provider_request( 'jetengine', $request );
	}

	public static function endpoint() {
		if ( is_string( self::$endpoint ) && '' !== self::$endpoint ) return self::$endpoint;
		$routes = self::routes();
		if ( empty( $routes ) ) return '';
		$preferred = array( '/jet-engine/v1/mcp', '/jet-engine/v1/mcp/' );
		foreach ( $preferred as $route ) {
			if ( isset( $routes[ $route ] ) ) {
				self::$endpoint = $route;
				return self::$endpoint;
			}
		}
		foreach ( array_keys( $routes ) as $route ) {
			$normalized = strtolower( (string) $route );
			if ( false !== strpos( $normalized, 'jet-engine' ) && preg_match( '#/mcp/?$#', $normalized ) ) {
				self::$endpoint = (string) $route;
				return self::$endpoint;
			}
		}
		// Do not cache a negative lookup. During rest_api_init another provider may
		// still register its route at a later priority in the same lifecycle.
		return '';
	}

	public static function registry_endpoint() {
		if ( is_string( self::$registry_endpoint ) && '' !== self::$registry_endpoint ) return self::$registry_endpoint;
		$routes = self::routes();
		if ( empty( $routes ) ) return '';
		$preferred = array( '/jet-engine/v1/mcp-tools', '/jet-engine/v1/mcp-tools/' );
		foreach ( $preferred as $route ) {
			if ( isset( $routes[ $route ] ) ) {
				self::$registry_endpoint = $route;
				return self::$registry_endpoint;
			}
		}
		foreach ( array_keys( $routes ) as $route ) {
			$normalized = strtolower( (string) $route );
			if ( false !== strpos( $normalized, 'jet-engine' ) && preg_match( '#/mcp-tools/?$#', $normalized ) ) {
				self::$registry_endpoint = (string) $route;
				return self::$registry_endpoint;
			}
		}
		return '';
	}

	private static function rest_run_route_available() {
		if ( true === self::$run_route_available ) return true;
		$routes = self::routes();
		foreach ( array_keys( $routes ) as $route ) {
			$normalized = strtolower( (string) $route );
			if ( false !== strpos( $normalized, 'jet-engine' ) && false !== strpos( $normalized, '/mcp-tools/run/' ) ) {
				self::$run_route_available = true;
				return true;
			}
		}
		// Do not cache false; a later rest_api_init callback may still register it.
		return false;
	}

	public static function transport_status() {
		$mcp = self::endpoint();
		$registry = self::registry_endpoint();
		$run = self::rest_run_route_available();
		$isolated = self::isolated_transport_status();
		$isolated_registry = ! empty( $isolated['registry_available'] );
		$isolated_run = ! empty( $isolated['run_available'] );
		return array(
			'contract' => self::CONTRACT,
			'mcp_jsonrpc_endpoint' => $mcp,
			'mcp_jsonrpc_available' => '' !== $mcp,
			'native_rest_registry_endpoint' => $registry,
			'native_rest_registry_available' => '' !== $registry,
			'native_rest_run_available' => (bool) $run,
			'isolated_native_rest_registry_available' => $isolated_registry,
			'isolated_native_rest_run_available' => $isolated_run,
			'isolated_native_rest_retained_route_count' => isset( $isolated['retained_route_count'] ) ? (int) $isolated['retained_route_count'] : 0,
			'raw_provider_routes_exposed' => false,
			'preferred_transport' => '' !== $mcp ? 'mcp-jsonrpc' : ( '' !== $registry && $run ? 'native-rest-tools' : ( $isolated_registry && $isolated_run ? 'isolated-native-rest-tools' : 'unavailable' ) ),
			'available' => '' !== $mcp || ( '' !== $registry && $run ) || ( $isolated_registry && $isolated_run ),
		);
	}

	public static function available() {
		$status = self::transport_status();
		return ! empty( $status['available'] );
	}

	private static function rpc( $method, array $params = array(), $session_id = '', $notification = false ) {
		$endpoint = self::endpoint();
		if ( '' === $endpoint ) return new WP_Error( 'mad4b_jetengine_mcp_endpoint_unavailable', 'JetEngine MCP JSON-RPC endpoint is not registered.' );
		$request = new WP_REST_Request( 'POST', $endpoint );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_header( 'accept', 'application/json, text/event-stream' );
		if ( '' !== $session_id ) $request->set_header( 'mcp-session-id', $session_id );
		$payload = array( 'jsonrpc' => '2.0', 'method' => (string) $method );
		if ( ! $notification ) $payload['id'] = substr( hash( 'sha256', $method . '|' . wp_json_encode( $params ) . '|' . microtime( true ) ), 0, 24 );
		if ( $params ) $payload['params'] = $params;
		$request->set_body( wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		$response = rest_do_request( $request );
		if ( is_wp_error( $response ) ) return $response;
		$status = (int) $response->get_status();
		if ( $status < 200 || $status >= 300 ) return new WP_Error( 'mad4b_jetengine_mcp_http_error', 'JetEngine MCP returned a non-success HTTP status.', array( 'status' => $status, 'method' => $method ) );
		$data = $response->get_data();
		if ( is_string( $data ) ) {
			$decoded = json_decode( $data, true );
			if ( is_array( $decoded ) ) $data = $decoded;
		}
		if ( $notification ) return array( 'data' => is_array( $data ) ? $data : array(), 'session_id' => $session_id, 'headers' => $response->get_headers() );
		if ( ! is_array( $data ) ) return new WP_Error( 'mad4b_jetengine_mcp_response_invalid', 'JetEngine MCP response is not a JSON object.' );
		if ( isset( $data['error'] ) ) return new WP_Error( 'mad4b_jetengine_mcp_rpc_error', 'JetEngine MCP returned a JSON-RPC error.', array( 'method' => $method, 'error' => $data['error'] ) );
		$headers = $response->get_headers();
		$new_session = $session_id;
		foreach ( $headers as $key => $value ) {
			if ( 'mcp-session-id' === strtolower( (string) $key ) ) {
				$new_session = is_array( $value ) ? (string) reset( $value ) : (string) $value;
				break;
			}
		}
		return array( 'data' => $data, 'session_id' => $new_session, 'headers' => $headers );
	}

	private static function initialize() {
		$init = self::rpc( 'initialize', array(
			'protocolVersion' => self::PROTOCOL_VERSION,
			'capabilities' => (object) array(),
			'clientInfo' => array( 'name' => self::CLIENT_NAME, 'version' => defined( 'MAD4B_SCP_VERSION' ) ? MAD4B_SCP_VERSION : 'unknown' ),
		) );
		if ( is_wp_error( $init ) ) return $init;
		$session_id = isset( $init['session_id'] ) ? (string) $init['session_id'] : '';
		$ready = self::rpc( 'notifications/initialized', array(), $session_id, true );
		if ( is_wp_error( $ready ) ) return $ready;
		return array( 'session_id' => $session_id, 'initialize' => $init['data'] );
	}

	private static function schema_hash( array $schema ) {
		return hash( 'sha256', wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}

	private static function normalize_tool( array $tool, $channel = 'mcp-jsonrpc', $fallback_name = '' ) {
		$name = '';
		foreach ( array( 'name', 'slug', 'id', 'key', 'feature', 'tool' ) as $key ) {
			if ( isset( $tool[ $key ] ) && is_scalar( $tool[ $key ] ) && '' !== trim( (string) $tool[ $key ] ) ) { $name = trim( (string) $tool[ $key ] ); break; }
		}
		if ( '' === $name && '' !== $fallback_name ) $name = (string) $fallback_name;

		$raw_schema = array();
		foreach ( array( 'inputSchema', 'input_schema', 'parameters', 'args', 'schema' ) as $key ) {
			if ( isset( $tool[ $key ] ) && is_array( $tool[ $key ] ) ) { $raw_schema = $tool[ $key ]; break; }
		}
		$schema = self::normalize_input_schema( $raw_schema );
		$annotations = isset( $tool['annotations'] ) && is_array( $tool['annotations'] ) ? $tool['annotations'] : array();
		$readonly = null;
		if ( array_key_exists( 'readOnlyHint', $annotations ) ) $readonly = (bool) $annotations['readOnlyHint'];
		elseif ( array_key_exists( 'readonly', $annotations ) ) $readonly = (bool) $annotations['readonly'];
		else {
			foreach ( array( 'readonly', 'read_only', 'is_readonly', 'is_read_only' ) as $key ) {
				if ( array_key_exists( $key, $tool ) ) { $readonly = (bool) $tool[ $key ]; break; }
			}
		}
		$type = isset( $tool['type'] ) && is_scalar( $tool['type'] ) ? strtolower( (string) $tool['type'] ) : ( isset( $tool['kind'] ) && is_scalar( $tool['kind'] ) ? strtolower( (string) $tool['kind'] ) : '' );
		if ( null === $readonly && 'resource' === $type ) $readonly = true;
		if ( null === $readonly && 'tool' === $type ) $readonly = false;
		if ( null === $readonly && 0 === strpos( strtolower( $name ), 'resource-' ) ) $readonly = true;

		$label = $name;
		foreach ( array( 'title', 'label', 'name' ) as $key ) {
			if ( isset( $tool[ $key ] ) && is_scalar( $tool[ $key ] ) && '' !== trim( (string) $tool[ $key ] ) ) { $label = (string) $tool[ $key ]; break; }
		}
		$description = '';
		foreach ( array( 'description', 'desc', 'help' ) as $key ) {
			if ( isset( $tool[ $key ] ) && is_scalar( $tool[ $key ] ) ) { $description = (string) $tool[ $key ]; break; }
		}

		return array(
			'name' => $name,
			'label' => $label,
			'description' => $description,
			'category' => 'jetengine-mcp',
			'readonly' => $readonly,
			'destructive' => array_key_exists( 'destructiveHint', $annotations ) ? (bool) $annotations['destructiveHint'] : ( array_key_exists( 'destructive', $tool ) ? (bool) $tool['destructive'] : null ),
			'idempotent' => array_key_exists( 'idempotentHint', $annotations ) ? (bool) $annotations['idempotentHint'] : ( array_key_exists( 'idempotent', $tool ) ? (bool) $tool['idempotent'] : null ),
			'input_schema' => $schema,
			'output_schema' => isset( $tool['outputSchema'] ) && is_array( $tool['outputSchema'] ) ? $tool['outputSchema'] : ( isset( $tool['output_schema'] ) && is_array( $tool['output_schema'] ) ? $tool['output_schema'] : array() ),
			'schema_sha256' => self::schema_hash( $schema ),
			// Keep the bridge-facing transport stable; channel identifies which native
			// JetEngine transport actually performs discovery/execution.
			'provider_transport' => 'jetengine-mcp',
			'provider_native_channel' => (string) $channel,
		);
	}

	private static function is_list_array( array $value ) {
		$expected = 0;
		foreach ( array_keys( $value ) as $key ) {
			if ( $key !== $expected ) return false;
			++$expected;
		}
		return true;
	}

	private static function normalize_input_schema( array $raw ) {
		if ( empty( $raw ) ) return array();
		if ( isset( $raw['type'] ) || isset( $raw['properties'] ) || isset( $raw['anyOf'] ) || isset( $raw['oneOf'] ) || isset( $raw['allOf'] ) ) return $raw;

		$properties = array();
		$required = array();
		if ( self::is_list_array( $raw ) ) {
			foreach ( $raw as $item ) {
				if ( ! is_array( $item ) ) continue;
				$name = isset( $item['name'] ) && is_scalar( $item['name'] ) ? (string) $item['name'] : ( isset( $item['key'] ) && is_scalar( $item['key'] ) ? (string) $item['key'] : '' );
				if ( '' === $name ) continue;
				$schema = $item;
				unset( $schema['name'], $schema['key'] );
				if ( ! empty( $schema['required'] ) ) $required[] = $name;
				unset( $schema['required'] );
				$properties[ $name ] = $schema;
			}
		} else {
			foreach ( $raw as $name => $item ) {
				if ( ! is_array( $item ) || ! is_string( $name ) || '' === $name ) continue;
				$schema = $item;
				if ( ! empty( $schema['required'] ) ) $required[] = $name;
				unset( $schema['required'] );
				$properties[ $name ] = $schema;
			}
		}
		if ( empty( $properties ) ) return array();
		$out = array( 'type' => 'object', 'properties' => $properties, 'additionalProperties' => false );
		if ( ! empty( $required ) ) $out['required'] = array_values( array_unique( $required ) );
		return $out;
	}

	private static function extract_rest_tool_entries( $data ) {
		if ( ! is_array( $data ) ) return array();
		foreach ( array( 'tools', 'items', 'features' ) as $key ) {
			if ( isset( $data[ $key ] ) && is_array( $data[ $key ] ) ) return $data[ $key ];
		}
		foreach ( array( 'data', 'result' ) as $key ) {
			if ( isset( $data[ $key ] ) && is_array( $data[ $key ] ) ) {
				$nested = self::extract_rest_tool_entries( $data[ $key ] );
				if ( ! empty( $nested ) ) return $nested;
			}
		}
		return $data;
	}

	private static function mcp_tools() {
		if ( '' === self::endpoint() ) return new WP_Error( 'mad4b_jetengine_mcp_endpoint_unavailable', 'JetEngine MCP JSON-RPC endpoint is not registered.' );
		$init = self::initialize();
		if ( is_wp_error( $init ) ) return $init;
		$list = self::rpc( 'tools/list', array(), (string) $init['session_id'] );
		if ( is_wp_error( $list ) ) return $list;
		$data = isset( $list['data']['result'] ) && is_array( $list['data']['result'] ) ? $list['data']['result'] : array();
		$tools = isset( $data['tools'] ) && is_array( $data['tools'] ) ? $data['tools'] : array();
		$normalized = array();
		foreach ( $tools as $tool ) {
			if ( ! is_array( $tool ) || empty( $tool['name'] ) ) continue;
			$row = self::normalize_tool( $tool, 'mcp-jsonrpc' );
			if ( '' !== $row['name'] ) $normalized[ $row['name'] ] = $row;
		}
		ksort( $normalized, SORT_STRING );
		return $normalized;
	}

	private static function rest_registry_tools( $isolated = false ) {
		$endpoint = $isolated ? '/jet-engine/v1/mcp-tools' : self::registry_endpoint();
		if ( $isolated ) {
			if ( ! self::isolated_rest_tools_available() ) return new WP_Error( 'mad4b_jetengine_isolated_rest_tools_unavailable', 'JetEngine isolated native REST registry/run handlers are not both retained.' );
		} elseif ( '' === $endpoint || ! self::rest_run_route_available() ) {
			return new WP_Error( 'mad4b_jetengine_rest_tools_unavailable', 'JetEngine native REST tool registry/run routes are not both registered.' );
		}
		$request = new WP_REST_Request( 'GET', $endpoint );
		$response = $isolated ? self::dispatch_isolated_request( $request ) : rest_do_request( $request );
		if ( is_wp_error( $response ) ) return $response;
		$status = (int) $response->get_status();
		if ( $status < 200 || $status >= 300 ) return new WP_Error( 'mad4b_jetengine_rest_tools_http_error', 'JetEngine native REST tool registry returned a non-success HTTP status.', array( 'status' => $status ) );
		$entries = self::extract_rest_tool_entries( $response->get_data() );
		if ( ! is_array( $entries ) ) return new WP_Error( 'mad4b_jetengine_rest_tools_response_invalid', 'JetEngine native REST tool registry did not return a tool collection.' );
		$normalized = array();
		foreach ( $entries as $key => $tool ) {
			if ( ! is_array( $tool ) ) continue;
			$fallback = is_string( $key ) ? $key : '';
			$row = self::normalize_tool( $tool, $isolated ? 'isolated-native-rest-tools' : 'native-rest-tools', $fallback );
			if ( '' === $row['name'] ) continue;
			$normalized[ $row['name'] ] = $row;
		}
		ksort( $normalized, SORT_STRING );
		return $normalized;
	}

	public static function tools() {
		if ( null !== self::$tools ) return self::$tools;
		$errors = array();
		if ( '' !== self::endpoint() ) {
			$tools = self::mcp_tools();
			if ( ! is_wp_error( $tools ) && ! empty( $tools ) ) { self::$tools = $tools; return self::$tools; }
			if ( is_wp_error( $tools ) ) $errors['mcp_jsonrpc'] = $tools->get_error_code();
		}
		if ( '' !== self::registry_endpoint() && self::rest_run_route_available() ) {
			$tools = self::rest_registry_tools();
			if ( ! is_wp_error( $tools ) && ! empty( $tools ) ) { self::$tools = $tools; return self::$tools; }
			if ( is_wp_error( $tools ) ) $errors['native_rest_tools'] = $tools->get_error_code();
		}
		if ( self::isolated_rest_tools_available() ) {
			$tools = self::rest_registry_tools( true );
			if ( ! is_wp_error( $tools ) && ! empty( $tools ) ) { self::$tools = $tools; return self::$tools; }
			if ( is_wp_error( $tools ) ) $errors['isolated_native_rest_tools'] = $tools->get_error_code();
		}
		return new WP_Error( 'mad4b_jetengine_native_tools_unavailable', 'JetEngine exposes no discoverable native tool collection through its MCP or native REST tool transports.', array( 'transport_status' => self::transport_status(), 'errors' => $errors ) );
	}

	public static function validate_tool_input( $tool_name, array $arguments, $expected_schema_sha256 ) {
		$tools = self::tools();
		if ( is_wp_error( $tools ) ) return $tools;
		$tool_name = (string) $tool_name;
		if ( ! isset( $tools[ $tool_name ] ) ) return new WP_Error( 'mad4b_jetengine_mcp_tool_missing', 'Planned JetEngine native tool is no longer available.', array( 'tool' => $tool_name ) );
		$current_hash = (string) $tools[ $tool_name ]['schema_sha256'];
		$expected_hash = strtolower( trim( (string) $expected_schema_sha256 ) );
		if ( '' === $expected_hash || ! hash_equals( $current_hash, $expected_hash ) ) return new WP_Error( 'mad4b_jetengine_mcp_schema_drift', 'JetEngine native tool schema changed since planning.', array( 'tool' => $tool_name, 'current_schema_sha256' => $current_hash ) );
		$schema = isset( $tools[ $tool_name ]['input_schema'] ) && is_array( $tools[ $tool_name ]['input_schema'] ) ? $tools[ $tool_name ]['input_schema'] : array();
		if ( empty( $schema ) ) {
			return empty( $arguments ) ? true : new WP_Error( 'mad4b_jetengine_mcp_input_schema_unavailable', 'JetEngine native tool did not publish an input schema for the requested arguments.', array( 'tool' => $tool_name ) );
		}
		if ( ! function_exists( 'rest_validate_value_from_schema' ) ) return new WP_Error( 'mad4b_jetengine_mcp_input_validation_unavailable', 'WordPress REST schema validation is unavailable.' );
		$valid = rest_validate_value_from_schema( $arguments, $schema, 'arguments' );
		if ( is_wp_error( $valid ) ) {
			return new WP_Error(
				'mad4b_jetengine_mcp_input_invalid',
				'JetEngine native tool input does not satisfy the exact discovered input schema.',
				array( 'tool' => $tool_name, 'validation_error_code' => $valid->get_error_code(), 'validation_error_message' => $valid->get_error_message() )
			);
		}
		return true;
	}

	private static function response_header( $response, $name ) {
		if ( ! is_object( $response ) || ! method_exists( $response, 'get_headers' ) ) return '';
		$headers = $response->get_headers();
		if ( ! is_array( $headers ) ) return '';
		foreach ( $headers as $key => $value ) {
			if ( strtolower( (string) $key ) !== strtolower( (string) $name ) ) continue;
			return is_array( $value ) ? (string) reset( $value ) : (string) $value;
		}
		return '';
	}

	private static function provider_response_error_code( $data ) {
		if ( ! is_array( $data ) ) return '';
		if ( isset( $data['code'] ) && is_scalar( $data['code'] ) ) return substr( sanitize_key( (string) $data['code'] ), 0, 120 );
		if ( isset( $data['error'] ) && is_array( $data['error'] ) && isset( $data['error']['code'] ) && is_scalar( $data['error']['code'] ) ) {
			return substr( sanitize_key( (string) $data['error']['code'] ), 0, 120 );
		}
		return '';
	}

	private static function call_rest_tool( $tool_name, array $arguments, $isolated = false, $operation = '' ) {
		if ( $isolated ) {
			if ( ! self::isolated_rest_tools_available() ) return new WP_Error( 'mad4b_jetengine_isolated_rest_tool_run_unavailable', 'JetEngine isolated native REST tool run handler is unavailable.' );
		} elseif ( ! self::rest_run_route_available() ) {
			return new WP_Error( 'mad4b_jetengine_rest_tool_run_unavailable', 'JetEngine native REST tool run route is unavailable.' );
		}
		if ( ! preg_match( '#^[a-zA-Z0-9\-/]+$#', (string) $tool_name ) ) return new WP_Error( 'mad4b_jetengine_rest_tool_name_invalid', 'JetEngine native REST tool name cannot be represented by the provider run route.' );
		$route = '/jet-engine/v1/mcp-tools/run/' . ltrim( (string) $tool_name, '/' );
		$request = new WP_REST_Request( 'POST', $route );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body_params( array( 'input' => $arguments ) );
		$input_encoded = wp_json_encode( $arguments, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$input_digest = hash( 'sha256', false === $input_encoded ? '' : $input_encoded );
		$envelope = array(
			'contract' => 'mad4b.jetengine-native-rest-execution-envelope.v1',
			'operation' => sanitize_key( (string) $operation ),
			'native_tool_name' => (string) $tool_name,
			'internal_route' => $route,
			'http_method' => 'POST',
			'input_sha256' => $input_digest,
			'isolated' => (bool) $isolated,
		);
		$envelope_encoded = wp_json_encode( $envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$request_envelope_digest = hash( 'sha256', false === $envelope_encoded ? '' : $envelope_encoded );

		$response = $isolated ? self::dispatch_isolated_request( $request ) : rest_do_request( $request );
		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				$response->get_error_code(),
				$response->get_error_message(),
				array(
					'contract' => 'mad4b.jetengine-native-rest-execution-diagnostic.v1',
					'operation' => sanitize_key( (string) $operation ),
					'native_tool_name' => (string) $tool_name,
					'internal_route' => $route,
					'http_method' => 'POST',
					'http_status' => 0,
					'provider_response_error_code' => $response->get_error_code(),
					'permission_result' => $isolated ? 'internal_dispatch_wp_error' : 'external_rest_wp_error',
					'request_envelope_digest' => $request_envelope_digest,
					'callback_reached' => false,
				)
			);
		}
		$status = (int) $response->get_status();
		$data = $response->get_data();
		if ( $status < 200 || $status >= 300 ) {
			return new WP_Error(
				'mad4b_jetengine_rest_tool_http_error',
				'JetEngine native REST tool execution returned a non-success HTTP status.',
				array(
					'contract' => 'mad4b.jetengine-native-rest-execution-diagnostic.v1',
					'operation' => sanitize_key( (string) $operation ),
					'native_tool_name' => (string) $tool_name,
					'internal_route' => $route,
					'http_method' => 'POST',
					'http_status' => $status,
					'provider_response_error_code' => self::provider_response_error_code( $data ),
					'permission_result' => self::response_header( $response, 'X-MAD4B-Internal-Provider-Permission' ),
					'provider_permission_callback_present' => '1' === self::response_header( $response, 'X-MAD4B-Internal-Provider-Permission-Present' ),
					'request_envelope_digest' => $request_envelope_digest,
					'callback_reached' => '1' === self::response_header( $response, 'X-MAD4B-Internal-Provider-Callback-Reached' ),
				)
			);
		}
		return $data;
	}

	public static function call_tool( $tool_name, array $arguments, $expected_schema_sha256, $operation = '' ) {
		$valid = self::validate_tool_input( $tool_name, $arguments, $expected_schema_sha256 );
		if ( is_wp_error( $valid ) ) return $valid;
		$tools = self::tools();
		if ( is_wp_error( $tools ) || ! isset( $tools[ $tool_name ] ) ) return is_wp_error( $tools ) ? $tools : new WP_Error( 'mad4b_jetengine_mcp_tool_missing', 'Planned JetEngine native tool is no longer available.' );
		$channel = isset( $tools[ $tool_name ]['provider_native_channel'] ) ? (string) $tools[ $tool_name ]['provider_native_channel'] : 'mcp-jsonrpc';
		if ( 'native-rest-tools' === $channel ) return self::call_rest_tool( $tool_name, $arguments, false, $operation );
		if ( 'isolated-native-rest-tools' === $channel ) return self::call_rest_tool( $tool_name, $arguments, true, $operation );
		$init = self::initialize();
		if ( is_wp_error( $init ) ) return $init;
		$call = self::rpc( 'tools/call', array( 'name' => (string) $tool_name, 'arguments' => $arguments ), (string) $init['session_id'] );
		if ( is_wp_error( $call ) ) return $call;
		return isset( $call['data']['result'] ) ? $call['data']['result'] : $call['data'];
	}
}
