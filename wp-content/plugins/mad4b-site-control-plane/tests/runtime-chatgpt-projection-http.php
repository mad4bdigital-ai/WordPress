<?php
if ( ! defined( 'ABSPATH' ) ) exit;
$fail = static function ( $message ) { throw new RuntimeException( $message ); };
$wire = static function ( $response ) { return json_decode( wp_json_encode( $response->get_data() ), true ); };
$original = get_option( MAD4B_SCP_ChatGPT_Tool_Projection::OPTION, false );
$mint = new ReflectionMethod( 'MAD4B_SCP_Local_OAuth_Server', 'mint_access_token' );
$mint->setAccessible( true );
$resource = MAD4B_SCP_Local_OAuth_Server::resource_identifier();
$read = $mint->invoke( null, MAD4B_SCP_Local_OAuth_Server::CHATGPT_CIMD_CLIENT_ID, 1, $resource, array( 'mad4b:read' ) );
$step = $mint->invoke( null, MAD4B_SCP_Local_OAuth_Server::CHATGPT_CIMD_CLIENT_ID, 1, $resource, array( 'mad4b:read', MAD4B_SCP_OAuth_Resource_Bridge::AUTHORITY_STEP_UP_SCOPE ) );
if ( is_wp_error( $read ) || is_wp_error( $step ) ) $fail( 'Cannot mint projection HTTP proof tokens.' );
wp_set_current_user( 0 );
$dispatch = static function ( $bearer, $method, array $params, $session = '' ) use ( $wire ) {
    $request = new WP_REST_Request( 'POST', '/mcp/mad4b-chatgpt' );
    $request->set_header( 'Authorization', 'Bearer ' . $bearer );
    $request->set_header( 'Accept', 'application/json, text/event-stream' );
    $request->set_header( 'Content-Type', 'application/json' );
    $request->set_header( 'MCP-Protocol-Version', '2025-11-25' );
    if ( $session ) $request->set_header( 'Mcp-Session-Id', $session );
    $request->set_body( wp_json_encode( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params ) ) );
    $response = rest_ensure_response( rest_do_request( $request ) );
    $response = apply_filters( 'rest_post_dispatch', $response, rest_get_server(), $request );
    return array( $response, $wire( $response ) );
};
$initialize = static function ( $bearer ) use ( $dispatch, $fail ) {
    list( $response, $body ) = $dispatch( $bearer, 'initialize', array( 'protocolVersion' => '2025-11-25', 'clientInfo' => array( 'name' => 'projection-proof', 'version' => '1' ) ) );
    if ( 200 !== $response->get_status() || ! isset( $body['result'] ) ) $fail( 'Projection OAuth initialize failed.' );
    foreach ( $response->get_headers() as $key => $value ) if ( 'mcp-session-id' === strtolower( $key ) ) return is_array( $value ) ? reset( $value ) : $value;
    $fail( 'Projection initialize session missing.' );
};
$call = static function ( $bearer, $session, $name, array $args = array() ) use ( $dispatch ) {
    list( $response, $body ) = $dispatch( $bearer, 'tools/call', array( 'name' => $name, 'arguments' => $args ), $session );
    return $body;
};
$denied = static function ( array $body ) { return ! empty( $body['result']['isError'] ) || isset( $body['error'] ); };
try {
    $read_session = $initialize( $read );
    list( $response, $catalog ) = $dispatch( $read, 'tools/list', array(), $read_session );
    $names = array_column( $catalog['result']['tools'] ?? array(), 'name' );
    if ( ! in_array( 'mad4b-diagnostics-health', $names, true ) || in_array( 'mad4b-ci-unsafe-write-projection-fixture', $names, true ) ) $fail( 'Read catalog did not enforce dynamic classification.' );
    $unknown_name = 'mad4b-ci-unsafe-write-projection-fixture';
    if ( ! $denied( $call( $read, $read_session, $unknown_name ) ) ) $fail( 'Known hidden mutating tool executed with read scope.' );
    if ( ! $denied( $call( $read, $read_session, 'mad4b-chatgpt-tool-projection-apply' ) ) ) $fail( 'Read bearer applied a projection.' );
    $step_session = $initialize( $step );
    if ( ! $denied( $call( $step, $step_session, $unknown_name ) ) ) $fail( 'Ungoverned third-party mutation executed with step-up.' );
    if ( ! empty( $GLOBALS['mad4b_projection_unsafe_calls'] ) ) $fail( 'Unsafe Ability callback ran despite admission denial.' );
    $demotion = MAD4B_SCP_ChatGPT_Tool_Projection::plan( array( 'ability_names' => array() ) );
    if ( is_wp_error( $demotion ) || empty( $demotion['ready_for_apply'] ) ) $fail( 'Demotion plan failed.' );
    $apply_args = array( 'ability_names' => array(), 'expected_plan_sha256' => $demotion['plan_sha256'], 'confirmation' => MAD4B_SCP_ChatGPT_Tool_Projection::CONFIRMATION );
    if ( $denied( $call( $step, $step_session, 'mad4b-chatgpt-tool-projection-apply', $apply_args ) ) ) $fail( 'Exact step-up demotion failed.' );
    if ( ! $denied( $call( $step, $step_session, 'mad4b-chatgpt-tool-projection-apply', $apply_args ) ) ) $fail( 'Projection replay was accepted.' );
    if ( ! $denied( $call( $read, $read_session, 'mad4b-diagnostics-health' ) ) ) $fail( 'Cached tool executed after demotion.' );
    $promotion = MAD4B_SCP_ChatGPT_Tool_Projection::plan( array( 'ability_names' => array( 'mad4b/diagnostics-health' ) ) );
    if ( is_wp_error( $promotion ) || empty( $promotion['ready_for_apply'] ) ) $fail( 'Promotion plan failed.' );
    $promote_args = array( 'ability_names' => array( 'mad4b/diagnostics-health' ), 'expected_plan_sha256' => $promotion['plan_sha256'], 'confirmation' => MAD4B_SCP_ChatGPT_Tool_Projection::CONFIRMATION );
    if ( $denied( $call( $step, $step_session, 'mad4b-chatgpt-tool-projection-apply', $promote_args ) ) ) $fail( 'Exact step-up promotion failed.' );
    if ( $denied( $call( $read, $read_session, 'mad4b-diagnostics-health' ) ) ) $fail( 'Promoted read tool did not regain admission.' );
    $copied = get_option( MAD4B_SCP_ChatGPT_Tool_Projection::OPTION );
    $copied['binding']['origin'] = 'https://copied.invalid';
    update_option( MAD4B_SCP_ChatGPT_Tool_Projection::OPTION, $copied, false );
    if ( ! $denied( $call( $read, $read_session, 'mad4b-diagnostics-health' ) ) ) $fail( 'Copied origin retained tool execution.' );
    fwrite( STDOUT, "mad4b.projection-http-admission.v1: PASS\n" );
} finally {
    if ( false === $original ) delete_option( MAD4B_SCP_ChatGPT_Tool_Projection::OPTION );
    else update_option( MAD4B_SCP_ChatGPT_Tool_Projection::OPTION, $original, false );
}
