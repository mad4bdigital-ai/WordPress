<?php
if ( ! defined( 'ABSPATH' ) ) exit;
$fail = static function ( $message ) { throw new RuntimeException( $message ); };
$wire = static function ( $response ) { return json_decode( wp_json_encode( $response->get_data() ), true ); };
// Optional independent HTTP mode: every request boots WordPress in the server
// process; the WP-CLI process only mints fixtures and asserts responses.
$network_base = getenv( 'MAD4B_PROJECTION_NETWORK_BASE' );
$network = static function( $bearer, $route, $method, array $params = array(), array $headers = array() ) use ( $network_base, $fail ) {
    if ( getenv( 'MAD4B_PROJECTION_NETWORK_HOST' ) ) $headers['Host'] = getenv( 'MAD4B_PROJECTION_NETWORK_HOST' );
    $url = rtrim( $network_base, '/' ) . '/wp-json' . $route;
    $headers += array( 'Authorization' => 'Bearer ' . $bearer, 'Accept' => 'application/json, text/event-stream', 'Content-Type' => 'application/json' );
    $options = array( 'method' => $method, 'headers' => $headers, 'timeout' => 20, 'redirection' => 0 );
    if ( 'GET' === $method || 'HEAD' === $method ) $url = add_query_arg( $params, $url );
    else $options['body'] = wp_json_encode( $params );
    $raw = wp_remote_request( $url, $options );
    if ( is_wp_error( $raw ) ) $fail( 'Independent HTTP request failed: ' . $raw->get_error_code() );
    // The independent server may have changed projection state in its process.
    wp_cache_delete( MAD4B_SCP_ChatGPT_Tool_Projection::OPTION, 'options' );
    wp_cache_delete( 'notoptions', 'options' );
    $bytes = wp_remote_retrieve_body( $raw );
    $binary = false !== strpos( (string) wp_remote_retrieve_header( $raw, 'content-type' ), 'application/octet-stream' );
    $data = $binary ? null : json_decode( $bytes, true );
    $response = new WP_REST_Response( null === $data ? $bytes : $data, wp_remote_retrieve_response_code( $raw ) );
    foreach ( wp_remote_retrieve_headers( $raw ) as $name => $value ) $response->header( $name, $value );
    return $response;
};
$original = get_option( MAD4B_SCP_ChatGPT_Tool_Projection::OPTION, false );
$mint = new ReflectionMethod( 'MAD4B_SCP_Local_OAuth_Server', 'mint_access_token' );
$mint->setAccessible( true );
$resource = MAD4B_SCP_Local_OAuth_Server::resource_identifier();
$read = $mint->invoke( null, MAD4B_SCP_Local_OAuth_Server::CHATGPT_CIMD_CLIENT_ID, 1, $resource, array( 'mad4b:read' ) );
$step = $mint->invoke( null, MAD4B_SCP_Local_OAuth_Server::CHATGPT_CIMD_CLIENT_ID, 1, $resource, array( 'mad4b:read', MAD4B_SCP_OAuth_Resource_Bridge::AUTHORITY_STEP_UP_SCOPE ) );
if ( is_wp_error( $read ) || is_wp_error( $step ) ) $fail( 'Cannot mint projection HTTP proof tokens.' );
wp_set_current_user( 0 );
$dispatch = static function ( $bearer, $method, array $params, $session = '' ) use ( $wire, $network_base, $network ) {
    if ( $network_base ) {
        $headers = array( 'MCP-Protocol-Version' => '2025-11-25' );
        if ( $session ) $headers['Mcp-Session-Id'] = $session;
        $response = $network( $bearer, '/mcp/mad4b-chatgpt', 'POST', array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => empty( $params ) ? new stdClass() : $params ), $headers );
        return array( $response, $wire( $response ) );
    }
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
$gateway = static function ( $bearer, array $payload ) use ( $wire, $network_base, $network ) {
    if ( $network_base ) {
        $response = $network( $bearer, '/mad4b/v1/capability-gateway', 'POST', $payload );
        return array( $response, $wire( $response ) );
    }
    $request = new WP_REST_Request( 'POST', '/mad4b/v1/capability-gateway' );
    $request->set_header( 'Authorization', 'Bearer ' . $bearer );
    $request->set_header( 'Accept', 'application/json' );
    $request->set_header( 'Content-Type', 'application/json' );
    $request->set_body( wp_json_encode( $payload ) );
    $response = rest_ensure_response( rest_do_request( $request ) );
    $response = apply_filters( 'rest_post_dispatch', $response, rest_get_server(), $request );
    return array( $response, $wire( $response ) );
};
$initialize = static function ( $bearer ) use ( $dispatch, $fail ) {
    list( $response, $body ) = $dispatch( $bearer, 'initialize', array( 'protocolVersion' => '2025-11-25', 'capabilities' => new stdClass(), 'clientInfo' => array( 'name' => 'projection-proof', 'version' => '1' ) ) );
    if ( 200 !== $response->get_status() || ! isset( $body['result'] ) ) $fail( 'Projection OAuth initialize failed: ' . $response->get_status() . ' ' . wp_json_encode( $body ) );
    foreach ( $response->get_headers() as $key => $value ) if ( 'mcp-session-id' === strtolower( $key ) ) return is_array( $value ) ? reset( $value ) : $value;
    $fail( 'Projection initialize session missing.' );
};
$call = static function ( $bearer, $session, $name, array $args = array() ) use ( $dispatch ) {
    list( $response, $body ) = $dispatch( $bearer, 'tools/call', array( 'name' => $name, 'arguments' => $args ), $session );
    return $body;
};
$denied = static function ( array $body ) { return ! empty( $body['result']['isError'] ) || isset( $body['error'] ); };
$rest_catalog = static function( $bearer, $path, array $params = array(), $method = 'GET' ) use ( $network_base, $network ) {
    if ( $network_base ) return array( $network( $bearer, '/mad4b/v1/ability-catalog/' . $path, $method, $params ), null );
    wp_set_current_user( 0 );
    $request = new WP_REST_Request( $method, '/mad4b/v1/ability-catalog/' . $path );
    if ( $bearer ) $request->set_header( 'Authorization', 'Bearer ' . $bearer );
    $request->set_query_params( $params );
    return array( rest_ensure_response( rest_do_request( $request ) ), $request );
};
try {

    list( $rest_manifest ) = $rest_catalog( $read, 'manifest', array( 'limit' => 1 ) );
    if ( 200 !== $rest_manifest->get_status() ) $fail( 'Authenticated native REST manifest failed.' );
    $manifest = $rest_manifest->get_data(); $descriptor = $manifest['items'][0]['wire'] ?? null;
    if ( ! $descriptor ) $fail( 'Official wire schema descriptor missing.' );
    list( $chunk_response, $chunk_request ) = $rest_catalog( $read, 'schemas/' . $descriptor['sha256'] . '/chunks/0', array( 'snapshot' => $manifest['snapshot'], 'schema_format' => 'wire' ) );
    if ( 200 !== $chunk_response->get_status() || ! is_string( $chunk_response->get_data() ) ) $fail( 'REST binary chunk unavailable.' );
    $chunk_headers = array_change_key_case( $chunk_response->get_headers(), CASE_LOWER );
    if ( hash( 'sha256', $chunk_response->get_data() ) !== $chunk_headers['x-mad4b-content-sha256'] ) $fail( 'Binary REST integrity failure.' );
    if ( $network_base ) {
        list( $head_response ) = $rest_catalog( $read, 'schemas/' . $descriptor['sha256'] . '/chunks/0', array( 'snapshot' => $manifest['snapshot'], 'schema_format' => 'wire' ), 'HEAD' );
        if ( 200 !== $head_response->get_status() || '' !== $head_response->get_data() ) $fail( 'Independent HEAD returned a body or failed.' );
    } else {
    ob_start(); $served = MAD4B_SCP_Ability_Catalog_Transport::serve_binary( false, $chunk_response, $chunk_request, rest_get_server() ); $bytes = ob_get_clean();
    if ( ! $served || $bytes !== $chunk_response->get_data() ) $fail( 'REST server JSON-encoded raw bytes.' );
    $chunk_request->set_method( 'HEAD' ); ob_start(); MAD4B_SCP_Ability_Catalog_Transport::serve_binary( false, $chunk_response, $chunk_request, rest_get_server() ); $head = ob_get_clean();
    if ( '' !== $head ) $fail( 'HEAD returned a response body.' );
    }
    list( $no_auth ) = $rest_catalog( '', 'manifest' ); list( $bad_auth ) = $rest_catalog( 'invalid', 'manifest' );
    if ( $no_auth->get_status() < 400 || $bad_auth->get_status() < 400 ) $fail( 'REST authentication bypass.' );
    $developer_token = $mint->invoke( null, MAD4B_SCP_Local_OAuth_Server::CHATGPT_CIMD_CLIENT_ID, 1, MAD4B_SCP_OAuth_Resource_Bridge::resource_identifier( 'mad4b-developer' ), array( 'mad4b:read' ) );
    if ( is_wp_error( $developer_token ) ) $fail( 'Cannot mint wrong-audience proof token.' );
    list( $wrong_audience ) = $rest_catalog( $developer_token, 'manifest' ); if ( $wrong_audience->get_status() < 400 ) $fail( 'REST accepted wrong resource audience.' );
    list( $gateway_read_response, $gateway_read ) = $gateway( $read, array(
        'action' => 'negotiate',
        'client_capabilities' => array(
            'dynamic_tool_refresh' => true,
            'tools_list_changed' => true,
            'tools_list_pagination' => true,
        ),
    ) );
    if ( 200 !== $gateway_read_response->get_status() || 'fixed_dispatch' !== ( $gateway_read['exposure_mode'] ?? '' ) || empty( $gateway_read['transport']['rest_gateway_proven_by_request'] ) ) {
        $fail( 'Read bearer did not reach the OAuth-protected adaptive REST gateway in fixed-dispatch mode.' );
    }
    list( $gateway_step_response, $gateway_step ) = $gateway( $step, array(
        'action' => 'negotiate',
        'client_capabilities' => array(
            'dynamic_tool_refresh' => true,
            'tools_list_changed' => true,
            'tools_list_pagination' => true,
        ),
    ) );
    if ( 200 !== $gateway_step_response->get_status() || 'dynamic_projection' !== ( $gateway_step['exposure_mode'] ?? '' ) || 'ready' !== ( $gateway_step['dynamic_projection_state'] ?? '' ) ) {
        $fail( 'Exact ChatGPT step-up bearer did not negotiate dynamic projection readiness over REST.' );
    }
    list( $gateway_search_response, $gateway_search ) = $gateway( $read, array(
        'action' => 'search',
        'task' => 'diagnostics health',
        'limit' => 8,
    ) );
    $gateway_search_names = array_column( $gateway_search['items'] ?? array(), 'ability_name' );
    if ( 200 !== $gateway_search_response->get_status() || ! in_array( 'mad4b/diagnostics-health', $gateway_search_names, true ) ) {
        $fail( 'OAuth-protected REST task discovery did not return the expected governed read Ability.' );
    }

    $read_session = $initialize( $read );
    $expired = $call( $read, $read_session, 'mad4b-chatgpt-tool-projection-discover', array( 'transport_action' => 'schema', 'snapshot' => str_repeat( 'f', 64 ), 'schema_sha256' => $descriptor['sha256'] ) );
    $error_text = $expired['result']['content'][0]['text'] ?? '';
    $error_envelope = json_decode( $error_text, true );
    if ( empty( $expired['result']['isError'] ) || 410 !== ( $error_envelope['status'] ?? null ) || 'mad4b.catalog-error.v1' !== ( $error_envelope['contract'] ?? '' ) ) $fail( 'Pinned Adapter lost catalog expiry status on the wire.' );
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

    if ( ! $network_base ) {
    $ability = wp_get_ability( 'mad4b/diagnostics-health' );
    $callback_property = ( new ReflectionObject( $ability ) )->getProperty( 'execute_callback' );
    $callback_property->setAccessible( true );
    $original_callback = $callback_property->getValue( $ability );
    try {
        $callback_property->setValue( $ability, static function() { throw new RuntimeException( 'Changed callback must never execute' ); } );
        if ( ! $denied( $call( $read, $read_session, 'mad4b-diagnostics-health' ) ) ) $fail( 'Cached callback receipt admitted in-place drift.' );
    } finally { $callback_property->setValue( $ability, $original_callback ); }
    }
    $copied = get_option( MAD4B_SCP_ChatGPT_Tool_Projection::OPTION );
    $copied['binding']['origin'] = 'https://copied.invalid';
    update_option( MAD4B_SCP_ChatGPT_Tool_Projection::OPTION, $copied, false );
    if ( ! $denied( $call( $read, $read_session, 'mad4b-diagnostics-health' ) ) ) $fail( 'Copied origin retained tool execution.' );
    if ( $network_base ) fwrite( STDOUT, "PASS independent HTTP: fresh registration, binary/HEAD, OAuth audience, projection promotion/demotion and replay\n" );
    fwrite( STDOUT, "mad4b.projection-http-admission.v2: PASS REST binary OAuth audience and dynamic tool admission\n" );
    fwrite( STDOUT, "mad4b.projection-http-admission.v1: PASS adaptive_rest_gateway=verified\n" );
} finally {
    if ( false === $original ) delete_option( MAD4B_SCP_ChatGPT_Tool_Projection::OPTION );
    else update_option( MAD4B_SCP_ChatGPT_Tool_Projection::OPTION, $original, false );
}
