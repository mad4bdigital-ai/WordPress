<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Internal JetEngine MCP client.
 *
 * Uses WordPress REST dispatch inside the current request/user context so no
 * extra credential or external loopback secret is required. It is discovery-
 * first and fail-closed: endpoint, session, tool name, schema identity and
 * native input are all resolved before any tools/call request is attempted.
 */
final class MAD4B_SCP_JetEngine_MCP_Client {
	const CONTRACT = 'mad4b.jetengine-mcp-client.v3';
	const PROTOCOL_VERSION = '2025-06-18';
	const CLIENT_NAME = 'mad4b-site-control-plane';

	private static $endpoint = null;
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

	public static function endpoint() {
		if ( is_string( self::$endpoint ) && '' !== self::$endpoint ) return self::$endpoint;
		$server = self::initialized_rest_server();
		if ( ! $server ) return '';
		$routes = $server->get_routes();
		if ( ! is_array( $routes ) ) return '';
		$preferred = array( '/jet-engine/v1/mcp', '/jet-engine/v1/mcp/' );
		foreach ( $preferred as $route ) {
			if ( isset( $routes[ $route ] ) ) {
				self::$endpoint = $route;
				return self::$endpoint;
			}
		}
		foreach ( array_keys( $routes ) as $route ) {
			$normalized = strtolower( (string) $route );
			if ( false !== strpos( $normalized, 'jet-engine' ) && false !== strpos( $normalized, '/mcp' ) ) {
				self::$endpoint = (string) $route;
				return self::$endpoint;
			}
		}
		// Do not cache a negative lookup. During rest_api_init another provider may
		// still register its route at a later priority in the same lifecycle.
		return '';
	}

	public static function available() {
		return '' !== self::endpoint();
	}

	private static function rpc( $method, array $params = array(), $session_id = '', $notification = false ) {
		$endpoint = self::endpoint();
		if ( '' === $endpoint ) return new WP_Error( 'mad4b_jetengine_mcp_endpoint_unavailable', 'JetEngine MCP REST endpoint is not registered.' );
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

	private static function normalize_tool( array $tool ) {
		$name = isset( $tool['name'] ) ? (string) $tool['name'] : '';
		$schema = isset( $tool['inputSchema'] ) && is_array( $tool['inputSchema'] ) ? $tool['inputSchema'] : array();
		$annotations = isset( $tool['annotations'] ) && is_array( $tool['annotations'] ) ? $tool['annotations'] : array();
		$readonly = null;
		if ( array_key_exists( 'readOnlyHint', $annotations ) ) $readonly = (bool) $annotations['readOnlyHint'];
		elseif ( array_key_exists( 'readonly', $annotations ) ) $readonly = (bool) $annotations['readonly'];
		return array(
			'name' => $name,
			'label' => isset( $tool['title'] ) ? (string) $tool['title'] : $name,
			'description' => isset( $tool['description'] ) ? (string) $tool['description'] : '',
			'category' => 'jetengine-mcp',
			'readonly' => $readonly,
			'destructive' => array_key_exists( 'destructiveHint', $annotations ) ? (bool) $annotations['destructiveHint'] : null,
			'idempotent' => array_key_exists( 'idempotentHint', $annotations ) ? (bool) $annotations['idempotentHint'] : null,
			'input_schema' => $schema,
			'output_schema' => isset( $tool['outputSchema'] ) && is_array( $tool['outputSchema'] ) ? $tool['outputSchema'] : array(),
			'schema_sha256' => self::schema_hash( $schema ),
			'provider_transport' => 'jetengine-mcp',
		);
	}

	public static function tools() {
		if ( null !== self::$tools ) return self::$tools;
		$init = self::initialize();
		if ( is_wp_error( $init ) ) return $init;
		$list = self::rpc( 'tools/list', array(), (string) $init['session_id'] );
		if ( is_wp_error( $list ) ) return $list;
		$data = isset( $list['data']['result'] ) && is_array( $list['data']['result'] ) ? $list['data']['result'] : array();
		$tools = isset( $data['tools'] ) && is_array( $data['tools'] ) ? $data['tools'] : array();
		$normalized_tools = array();
		foreach ( $tools as $tool ) {
			if ( ! is_array( $tool ) || empty( $tool['name'] ) ) continue;
			$row = self::normalize_tool( $tool );
			$normalized_tools[ $row['name'] ] = $row;
		}
		ksort( $normalized_tools, SORT_STRING );
		self::$tools = $normalized_tools;
		return self::$tools;
	}

	public static function validate_tool_input( $tool_name, array $arguments, $expected_schema_sha256 ) {
		$tools = self::tools();
		if ( is_wp_error( $tools ) ) return $tools;
		$tool_name = (string) $tool_name;
		if ( ! isset( $tools[ $tool_name ] ) ) return new WP_Error( 'mad4b_jetengine_mcp_tool_missing', 'Planned JetEngine MCP tool is no longer available.', array( 'tool' => $tool_name ) );
		$current_hash = (string) $tools[ $tool_name ]['schema_sha256'];
		$expected_hash = strtolower( trim( (string) $expected_schema_sha256 ) );
		if ( '' === $expected_hash || ! hash_equals( $current_hash, $expected_hash ) ) return new WP_Error( 'mad4b_jetengine_mcp_schema_drift', 'JetEngine MCP tool schema changed since planning.', array( 'tool' => $tool_name, 'current_schema_sha256' => $current_hash ) );
		$schema = isset( $tools[ $tool_name ]['input_schema'] ) && is_array( $tools[ $tool_name ]['input_schema'] ) ? $tools[ $tool_name ]['input_schema'] : array();
		if ( empty( $schema ) ) {
			return empty( $arguments ) ? true : new WP_Error( 'mad4b_jetengine_mcp_input_schema_unavailable', 'JetEngine MCP tool did not publish an input schema for the requested arguments.', array( 'tool' => $tool_name ) );
		}
		if ( ! function_exists( 'rest_validate_value_from_schema' ) ) return new WP_Error( 'mad4b_jetengine_mcp_input_validation_unavailable', 'WordPress REST schema validation is unavailable.' );
		$valid = rest_validate_value_from_schema( $arguments, $schema, 'arguments' );
		if ( is_wp_error( $valid ) ) {
			return new WP_Error(
				'mad4b_jetengine_mcp_input_invalid',
				'JetEngine MCP tool input does not satisfy the exact discovered input schema.',
				array( 'tool' => $tool_name, 'validation_error_code' => $valid->get_error_code(), 'validation_error_message' => $valid->get_error_message() )
			);
		}
		return true;
	}

	public static function call_tool( $tool_name, array $arguments, $expected_schema_sha256 ) {
		$valid = self::validate_tool_input( $tool_name, $arguments, $expected_schema_sha256 );
		if ( is_wp_error( $valid ) ) return $valid;
		$init = self::initialize();
		if ( is_wp_error( $init ) ) return $init;
		$call = self::rpc( 'tools/call', array( 'name' => (string) $tool_name, 'arguments' => $arguments ), (string) $init['session_id'] );
		if ( is_wp_error( $call ) ) return $call;
		return isset( $call['data']['result'] ) ? $call['data']['result'] : $call['data'];
	}
}
