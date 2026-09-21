<?php
/**
 * End-to-end local OAuth -> bearer -> REST MCP proof for the ChatGPT gateway.
 *
 * This exercises the exact seam missing from the earlier component tests:
 * local RS256 token verification, WordPress subject mapping, REST transport
 * permission, MCP initialize/session establishment, tools/list, and a real
 * tools/call through the packaged MCP Adapter.
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

foreach ( array( 'mad4b-site-info', 'mad4b-list-post-types', 'mad4b-list-plugins', 'mad4b-abilities-inventory', 'mad4b-diagnostics-health', 'mad4b-runtime-authority-status', 'mad4b-connection-status', 'mad4b-browser-acceptance-capabilities' ) as $required ) {
	if ( ! in_array( $required, $names, true ) ) $fail( 'OAuth bearer tools/list omitted a required safe-read tool.', $required );
}
// Exact enrolled Staging intentionally exposes the unified normal Read + Write
// catalog on this resource; only Breakglass Raw SQL remains outside ChatGPT.
foreach ( array( 'mad4b-filesystem-read', 'mad4b-filesystem-write', 'mad4b-database-select', 'mad4b-database-update', 'mad4b-content-update-post', 'mad4b-plugin-activate', 'mad4b-mutation-undo', 'mad4b-site-profile-feature-reenroll', 'mad4b-site-profile-write-enable' ) as $required_unified ) {
	if ( ! in_array( $required_unified, $names, true ) ) $fail( 'OAuth bearer tools/list omitted a required unified Staging Read/Write tool.', $required_unified );
}
if ( in_array( 'mad4b-database-raw-query', $names, true ) ) {
	$fail( 'OAuth bearer tools/list exposed Breakglass Raw SQL.', 'mad4b-database-raw-query' );
}

// Prove the exact packaged MCP Adapter can execute a safe Browser Acceptance
// read ability through tools/call and that its wire result is compatible with
// external MCP clients. Provider discovery may be empty in this generic OAuth
// runtime; the core contract and authority boundary must still be stable.
$browser_call = $dispatch(
	array(
		'jsonrpc' => '2.0',
		'id' => 72,
		'method' => 'tools/call',
		'params' => array(
			'name' => 'mad4b-browser-acceptance-capabilities',
			'arguments' => array(),
		),
	),
	$token,
	$session_id
);
if ( ! $browser_call instanceof WP_REST_Response ) $fail( 'Browser Acceptance tools/call did not return WP_REST_Response.', gettype( $browser_call ) );
if ( 200 !== (int) $browser_call->get_status() ) {
	$fail( 'OAuth bearer Browser Acceptance tools/call failed.', array( 'status' => $browser_call->get_status(), 'body' => $browser_call->get_data() ) );
}
$browser_call_data = $normalize( $browser_call->get_data() );
if ( ! is_array( $browser_call_data ) || isset( $browser_call_data['error'] ) ) {
	$fail( 'OAuth bearer Browser Acceptance tools/call returned a JSON-RPC error.', $browser_call_data );
}
$browser_rpc_result = isset( $browser_call_data['result'] ) && is_array( $browser_call_data['result'] ) ? $browser_call_data['result'] : array();
$browser_value = null;

if ( isset( $browser_rpc_result['structuredContent'] ) && is_array( $browser_rpc_result['structuredContent'] ) ) {
	$structured = $browser_rpc_result['structuredContent'];
	$browser_value = isset( $structured['result'] ) && is_array( $structured['result'] ) ? $structured['result'] : $structured;
}
if ( null === $browser_value && isset( $browser_rpc_result['content'] ) && is_array( $browser_rpc_result['content'] ) ) {
	foreach ( $browser_rpc_result['content'] as $item ) {
		if ( ! is_array( $item ) || 'text' !== ( isset( $item['type'] ) ? (string) $item['type'] : '' ) || ! isset( $item['text'] ) || ! is_string( $item['text'] ) ) continue;
		$decoded = json_decode( $item['text'], true );
		if ( ! is_array( $decoded ) ) continue;
		$browser_value = isset( $decoded['result'] ) && is_array( $decoded['result'] ) ? $decoded['result'] : $decoded;
		break;
	}
}
if ( null === $browser_value && isset( $browser_rpc_result['result'] ) && is_array( $browser_rpc_result['result'] ) ) {
	$browser_value = $browser_rpc_result['result'];
}
if ( ! is_array( $browser_value ) ) $fail( 'Browser Acceptance tools/call returned no decodable structured value.', $browser_call_data );
if ( 'mad4b.browser-acceptance-capabilities.v1' !== ( isset( $browser_value['contract'] ) ? (string) $browser_value['contract'] : '' ) ) {
	$fail( 'Browser Acceptance tools/call returned an unexpected contract.', $browser_value );
}
if ( empty( $browser_value['read_only'] ) || ! empty( $browser_value['authorizing'] ) ) {
	$fail( 'Browser Acceptance tools/call changed the read-only non-authorizing boundary.', $browser_value );
}
if ( 'external_browser_agent' !== ( isset( $browser_value['execution_mode'] ) ? (string) $browser_value['execution_mode'] : '' ) ) {
	$fail( 'Browser Acceptance tools/call changed execution ownership.', $browser_value );
}

remove_filter( 'pre_http_request', $http_spy, 9999 );

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
			'browser_tools_call_verified' => true,
			'browser_tools_call_contract' => (string) $browser_value['contract'],
		),
		JSON_UNESCAPED_SLASHES
	) . PHP_EOL
);
