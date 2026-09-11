<?php
/**
 * End-to-end local OAuth -> bearer -> REST MCP proof for the ChatGPT gateway.
 *
 * This exercises the exact seam missing from the earlier component tests:
 * local RS256 token verification, WordPress subject mapping, REST transport
 * permission, MCP initialize/session establishment, and tools/list.
 */

if ( ! defined( 'ABSPATH' ) ) {
	throw new RuntimeException( 'WordPress is not loaded.' );
}

$fail = static function ( $message, $data = null ) {
	throw new RuntimeException(
		$message . ( null !== $data ? ' ' . wp_json_encode( $data, JSON_UNESCAPED_SLASHES ) : '' )
	);
};
$normalize = static function ( $value ) {
	$encoded = wp_json_encode( $value );
	if ( false === $encoded ) return null;
	return json_decode( $encoded, true );
};

foreach ( array( 'MAD4B_SCP_Local_OAuth_Server', 'MAD4B_SCP_OAuth_Resource_Bridge', 'MAD4B_SCP_Servers' ) as $class ) {
	if ( ! class_exists( $class ) ) $fail( 'Required OAuth/MCP class is unavailable.', $class );
}
if ( ! function_exists( 'rest_do_request' ) || ! function_exists( 'rest_get_server' ) ) $fail( 'WordPress REST dispatcher is unavailable.' );

MAD4B_SCP_Local_OAuth_Server::ensure_runtime();
$local_status = MAD4B_SCP_Local_OAuth_Server::status();
$bridge_status = MAD4B_SCP_OAuth_Resource_Bridge::status();
if ( empty( $local_status['effective'] ) ) $fail( 'Local OAuth is not effective.', $local_status );
if ( empty( $bridge_status['effective'] ) || 'local' !== $bridge_status['authority_mode'] ) $fail( 'Local OAuth resource bridge is not effective in local mode.', $bridge_status );

$resource = MAD4B_SCP_Local_OAuth_Server::resource_identifier();
$mint = new ReflectionMethod( 'MAD4B_SCP_Local_OAuth_Server', 'mint_access_token' );
$mint->setAccessible( true );
$token = $mint->invoke( null, 'https://chatgpt.com/oauth/client.json', 1, $resource, array( 'mad4b:read' ) );
if ( is_wp_error( $token ) || ! is_string( $token ) || '' === $token ) {
	$fail( 'Unable to mint local ChatGPT bearer for REST proof.', is_wp_error( $token ) ? $token->get_error_code() : $token );
}

// Observe the final pre_http_request state after the local loopback guards have
// had a chance to preempt same-origin metadata/JWKS requests. Any request that
// remains unpreempted here would be a real outbound network dependency.
$http_seen = array();
$unpreempted_http = array();
$http_spy = static function ( $preempt, $args, $url ) use ( &$http_seen, &$unpreempted_http ) {
	$url = (string) $url;
	$http_seen[] = $url;
	if ( null === $preempt || false === $preempt ) $unpreempted_http[] = $url;
	return $preempt;
};
add_filter( 'pre_http_request', $http_spy, 9999, 3 );

$dispatch = static function ( array $payload, $bearer, $session_id = '' ) {
	$request = new WP_REST_Request( 'POST', '/mcp/mad4b-chatgpt' );
	$request->set_header( 'Authorization', 'Bearer ' . $bearer );
	$request->set_header( 'Accept', 'application/json, text/event-stream' );
	$request->set_header( 'Content-Type', 'application/json' );
	$request->set_header( 'MCP-Protocol-Version', '2025-11-25' );
	if ( '' !== $session_id ) $request->set_header( 'Mcp-Session-Id', $session_id );
	$request->set_body( wp_json_encode( $payload ) );

	// rest_do_request() intentionally stops at WP_REST_Server::dispatch(). The
	// live REST serving path then runs rest_post_dispatch; MCP Adapter attaches
	// Mcp-Session-Id at that stage. WordPress core tests mirror this same pattern.
	$response = rest_do_request( $request );
	$response = rest_ensure_response( $response );
	return apply_filters( 'rest_post_dispatch', $response, rest_get_server(), $request );
};

$initialize = $dispatch(
	array(
		'jsonrpc' => '2.0',
		'id' => 70,
		'method' => 'initialize',
		'params' => array(
			'protocolVersion' => '2025-11-25',
			'clientInfo' => array( 'name' => 'chatgpt-live-shape-ci', 'version' => '1.0.0' ),
		),
	),
	$token
);
if ( ! $initialize instanceof WP_REST_Response ) $fail( 'Initialize did not return WP_REST_Response.', gettype( $initialize ) );
if ( 200 !== (int) $initialize->get_status() ) $fail( 'OAuth bearer initialize failed.', array( 'status' => $initialize->get_status(), 'body' => $initialize->get_data() ) );
$initialize_data = $normalize( $initialize->get_data() );
if ( ! is_array( $initialize_data ) || ! isset( $initialize_data['result']['capabilities']['tools'] ) ) {
	$fail( 'Initialize did not advertise the MCP tools capability.', $initialize_data );
}
if ( empty( $initialize_data['result']['serverInfo']['name'] ) || 'MAD4B ChatGPT MCP' !== $initialize_data['result']['serverInfo']['name'] ) {
	$fail( 'Initialize returned an unexpected MCP server identity.', $initialize_data );
}
$headers = $initialize->get_headers();
$session_id = '';
foreach ( $headers as $name => $value ) {
	if ( 'mcp-session-id' === strtolower( (string) $name ) ) {
		$session_id = is_array( $value ) ? (string) reset( $value ) : (string) $value;
		break;
	}
}
if ( '' === $session_id ) $fail( 'Initialize did not establish an MCP session after rest_post_dispatch.', $headers );

$tools_response = $dispatch(
	array( 'jsonrpc' => '2.0', 'id' => 71, 'method' => 'tools/list', 'params' => array() ),
	$token,
	$session_id
);
remove_filter( 'pre_http_request', $http_spy, 9999 );

if ( ! $tools_response instanceof WP_REST_Response ) $fail( 'tools/list did not return WP_REST_Response.', gettype( $tools_response ) );
if ( 200 !== (int) $tools_response->get_status() ) $fail( 'OAuth bearer tools/list failed.', array( 'status' => $tools_response->get_status(), 'body' => $tools_response->get_data() ) );
$tools_data = $normalize( $tools_response->get_data() );
if ( isset( $tools_data['error'] ) ) $fail( 'OAuth bearer tools/list returned a JSON-RPC error.', $tools_data );
$tools = isset( $tools_data['result']['tools'] ) && is_array( $tools_data['result']['tools'] ) ? $tools_data['result']['tools'] : array();
if ( empty( $tools ) ) $fail( 'OAuth bearer tools/list returned an empty inventory.', $tools_data );

$names = array();
foreach ( $tools as $tool ) if ( is_array( $tool ) && isset( $tool['name'] ) && is_string( $tool['name'] ) ) $names[] = $tool['name'];
$names = array_values( array_unique( $names ) );
sort( $names );

foreach ( array( 'mad4b-site-info', 'mad4b-list-post-types', 'mad4b-list-plugins', 'mad4b-abilities-inventory', 'mad4b-diagnostics-health', 'mad4b-runtime-authority-status', 'mad4b-connection-status' ) as $required ) {
	if ( ! in_array( $required, $names, true ) ) $fail( 'OAuth bearer tools/list omitted a required safe-read tool.', $required );
}
foreach ( array( 'mad4b-filesystem-read', 'mad4b-filesystem-write', 'mad4b-database-select', 'mad4b-database-update', 'mad4b-database-raw-query', 'mad4b-content-update-post', 'mad4b-plugin-activate', 'mad4b-mutation-undo' ) as $forbidden ) {
	if ( in_array( $forbidden, $names, true ) ) $fail( 'OAuth bearer tools/list leaked a privileged tool.', $forbidden );
}

if ( ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_active() ) $fail( 'Verified bearer context was not active after MCP dispatch.' );
if ( 1 !== get_current_user_id() || ! current_user_can( 'manage_options' ) ) $fail( 'OAuth bearer did not map to the configured WordPress subject.' );
if ( ! empty( $unpreempted_http ) ) $fail( 'Local OAuth bearer verification attempted unpreempted outbound HTTP.', $unpreempted_http );

fwrite(
	STDOUT,
	'mad4b.site-control-plane.runtime-local-oauth-chatgpt-http.v2: PASS ' .
	wp_json_encode(
		array(
			'tool_count' => count( $names ),
			'http_filter_calls' => count( $http_seen ),
			'unpreempted_http_calls' => count( $unpreempted_http ),
			'session_established' => true,
		),
		JSON_UNESCAPED_SLASHES
	) . PHP_EOL
);
