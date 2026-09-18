<?php
/** Runtime proof for read-only local connection truth and staged admin rendering. */
if ( ! defined( 'ABSPATH' ) ) throw new RuntimeException( 'WordPress is not loaded.' );
$check = static function ( $condition, $message ) { if ( ! $condition ) throw new RuntimeException( $message ); };

$endpoint_matches_route = static function ( $endpoint, $server_id ) {
    $parts = wp_parse_url( (string) $endpoint );
    if ( ! is_array( $parts ) ) return false;
    $target = '/mcp/' . (string) $server_id;
    $path = isset( $parts['path'] ) ? (string) $parts['path'] : '';
    if ( substr( $path, -strlen( '/wp-json' . $target ) ) === '/wp-json' . $target ) return true;
    $query = array();
    if ( isset( $parts['query'] ) ) parse_str( (string) $parts['query'], $query );
    return isset( $query['rest_route'] ) && $target === rawurldecode( (string) $query['rest_route'] );
};

$render_tab = static function ( $tab ) {
    $had_tab = array_key_exists( 'tab', $_GET );
    $previous_tab = $had_tab ? $_GET['tab'] : null;
    $_GET['tab'] = $tab;
    ob_start();
    MAD4B_SCP_Connection_Admin_UI::render_page();
    $html = ob_get_clean();
    if ( $had_tab ) $_GET['tab'] = $previous_tab;
    else unset( $_GET['tab'] );
    return $html;
};

$check( current_user_can( 'manage_options' ), 'Connection readiness smoke requires an administrator.' );
$check( class_exists( 'MAD4B_SCP_Connection_Status' ), 'Connection status class unavailable.' );
$check( class_exists( 'MAD4B_SCP_External_Handshake_Evidence' ), 'External handshake evidence class unavailable.' );
$check( class_exists( 'MAD4B_SCP_Connection_Admin_UI' ), 'Connection admin UI class unavailable.' );
$check( class_exists( 'MAD4B_SCP_Admin_Experience' ), 'Shared staged admin experience class unavailable.' );
$check( class_exists( 'MAD4B_SCP_Transport_Context' ), 'Transport context class unavailable.' );
$check( function_exists( 'wp_has_ability' ) && wp_has_ability( 'mad4b/connection-status' ), 'Connection status ability is not registered.' );
$check( MAD4B_SCP_Servers::ability_is_mounted( 'mad4b-read', 'mad4b/connection-status' ), 'Connection status ability is not mounted on mad4b-read.' );

$check( class_exists( 'MAD4B_SCP_MCP_Registration_Bridge' ), 'MCP registration bridge class unavailable.' );
$rest_server_present_before_bridge_status = isset( $GLOBALS['wp_rest_server'] ) && is_object( $GLOBALS['wp_rest_server'] );
$registration_before_bridge_status = MAD4B_SCP_Servers::registration_status();
$mcp_init_count_before_bridge_status = did_action( 'mcp_adapter_init' );
$rest_init_count_before_bridge_status = did_action( 'rest_api_init' );
$bridge_status = MAD4B_SCP_MCP_Registration_Bridge::status();
$check( is_array( $bridge_status ), 'MCP registration bridge status must be an array.' );
$check( $rest_server_present_before_bridge_status === ( isset( $GLOBALS['wp_rest_server'] ) && is_object( $GLOBALS['wp_rest_server'] ) ), 'Reading MCP registration lifecycle created a REST server.' );
$check( $registration_before_bridge_status === MAD4B_SCP_Servers::registration_status(), 'Reading MCP registration lifecycle changed MCP server registration state.' );
$check( $mcp_init_count_before_bridge_status === did_action( 'mcp_adapter_init' ), 'Reading MCP registration lifecycle replayed MCP Adapter init.' );
$check( $rest_init_count_before_bridge_status === did_action( 'rest_api_init' ), 'Reading MCP registration lifecycle replayed REST init.' );

$status = MAD4B_SCP_Connection_Status::status();
$bridge_status_after_connection_status = MAD4B_SCP_MCP_Registration_Bridge::status();
$check( isset( $status['contract'] ) && 'mad4b.connection-readiness.v4' === $status['contract'], 'Unexpected connection readiness contract.' );

$check( isset( $status['mcp_registration_lifecycle'] ) && is_array( $status['mcp_registration_lifecycle'] ), 'MCP registration lifecycle projection missing from connection status.' );
$lifecycle = $status['mcp_registration_lifecycle'];
$lifecycle_boolean_fields = array(
    'rest_init_seen_before_bridge_boot', 'adapter_init_seen_before_bridge_boot',
    'missed_rest_recovery_scheduled', 'missed_rest_recovery_attempted', 'missed_rest_recovery_succeeded',
    'first_rest_observed', 'doing_plugins_loaded_at_first_rest', 'doing_init_at_first_rest',
    'jetengine_registry_class_loaded_at_first_rest', 'jetengine_registry_callback_present_at_first_rest',
    'jetengine_rest_manager_class_loaded_at_first_rest', 'jetengine_rest_manager_callback_present_at_first_rest',
    'mcp_adapter_callback_present_at_first_rest',
);
$lifecycle_integer_fields = array( 'mcp_adapter_init_count', 'rest_api_init_count', 'plugins_loaded_count_at_first_rest', 'init_count_at_first_rest', 'wp_loaded_count_at_first_rest' );
$lifecycle_string_fields = array( 'missed_rest_recovery_state', 'missed_rest_recovery_blocker', 'first_rest_classification' );
foreach ( $lifecycle_boolean_fields as $field ) {
    $check( array_key_exists( $field, $lifecycle ) && is_bool( $lifecycle[ $field ] ), 'Lifecycle boolean field missing or mistyped: ' . $field );
    $check( $lifecycle[ $field ] === ! empty( $bridge_status_after_connection_status[ $field ] ), 'Lifecycle boolean field did not project bridge status: ' . $field );
}
foreach ( $lifecycle_integer_fields as $field ) {
    $check( array_key_exists( $field, $lifecycle ) && is_int( $lifecycle[ $field ] ), 'Lifecycle integer field missing or mistyped: ' . $field );
    $check( $lifecycle[ $field ] === max( 0, (int) $bridge_status_after_connection_status[ $field ] ), 'Lifecycle integer field did not project bridge status: ' . $field );
}
foreach ( $lifecycle_string_fields as $field ) {
    $check( array_key_exists( $field, $lifecycle ) && is_string( $lifecycle[ $field ] ), 'Lifecycle string field missing or mistyped: ' . $field );
    $expected_lifecycle_string = isset( $bridge_status_after_connection_status[ $field ] ) ? sanitize_key( (string) $bridge_status_after_connection_status[ $field ] ) : '';
    $check( $lifecycle[ $field ] === $expected_lifecycle_string, 'Lifecycle string field did not project bridge status: ' . $field );
}
$check( isset( $lifecycle['caller_trace'] ) && is_array( $lifecycle['caller_trace'] ), 'First REST caller trace missing or mistyped.' );
$check( count( $lifecycle['caller_trace'] ) <= 16, 'First REST caller trace exceeded the bounded frame limit.' );
foreach ( $lifecycle['caller_trace'] as $frame ) {
    $check( is_array( $frame ), 'First REST caller trace frame is not an array.' );
    $relative_file = isset( $frame['relative_file'] ) ? (string) $frame['relative_file'] : '';
    $check( '' === $relative_file || ( '/' !== substr( $relative_file, 0, 1 ) && ! preg_match( '/^[A-Za-z]:[\\\\\/]/', $relative_file ) && false === strpos( $relative_file, '../' ) ), 'First REST caller trace exposed a non-relative path.' );
}
$check( ! empty( $lifecycle['first_rest_observed'] ), 'Connection status did not observe the first REST bootstrap.' );
$check( in_array( $lifecycle['first_rest_classification'], array( 'rest_before_plugins_loaded', 'rest_during_plugins_loaded_before_jetengine_registration', 'jetengine_callbacks_present_at_first_rest', 'first_rest_phase_undetermined' ), true ), 'Unexpected first REST classification.' );

$lifecycle_json = strtolower( (string) wp_json_encode( $lifecycle ) );
foreach ( array( 'client_secret', 'access_token', 'refresh_token', 'authorization_header', 'raw_token', 'app_id', 'oauth_subject', 'nonce', 'password' ) as $secret ) {
    $check( false === strpos( $lifecycle_json, $secret ), 'Lifecycle projection exposed forbidden material: ' . $secret );
}
$check(
    ! empty( $status['local_transport_ready'] ),
    'Clean local transport should be ready: blockers=' . wp_json_encode( $status['local_blockers'] )
    . ' registrations=' . wp_json_encode( MAD4B_SCP_Servers::registration_status() )
    . ' servers=' . wp_json_encode( $status['servers'] )
);
$check( empty( $status['remote_endpoint_preflight_ready'] ), 'Unconfigured OAuth resource server must not claim remote endpoint preflight readiness.' );
$is_https_target = 'https' === strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_SCHEME ) );
$check( $is_https_target ? ! in_array( 'https_required_for_remote_mcp', $status['remote_preflight_blockers'], true ) : in_array( 'https_required_for_remote_mcp', $status['remote_preflight_blockers'], true ), 'HTTPS remote blocker did not match the disposable target scheme.' );
$check( in_array( 'oauth_resource_bridge_not_configured', $status['remote_preflight_blockers'], true ), 'Unconfigured OAuth bridge did not block remote preflight.' );
$check( in_array( 'oauth_issuer_unconfigured', $status['remote_preflight_blockers'], true ), 'Missing OAuth issuer did not block remote preflight.' );
$check( in_array( 'oauth_wp_subject_unconfigured', $status['remote_preflight_blockers'], true ), 'Missing OAuth WordPress subject did not block remote preflight.' );
$check( isset( $status['oauth_resource_server'] ) && is_array( $status['oauth_resource_server'] ), 'OAuth resource-server truth missing from connection status.' );
$check( empty( $status['oauth_resource_server']['configured'] ), 'Disposable connection runtime unexpectedly reports OAuth configured.' );
$check( empty( $status['oauth_resource_server']['effective'] ), 'Disposable connection runtime unexpectedly reports OAuth effective.' );
$check( empty( $status['oauth_resource_server']['preflight_ready'] ), 'Disposable connection runtime unexpectedly reports OAuth preflight ready.' );
$check( empty( $status['connection_certified'] ), 'Repository/local inspection must never self-certify the external connection.' );
$check( empty( $status['external_handshake']['verified'] ), 'External handshake was incorrectly marked verified.' );
$check( 'unverified' === $status['external_handshake']['status'], 'Absent durable external evidence must remain explicitly unverified.' );
$check( in_array( 'external_handshake_unverified', $status['certification_blockers'], true ), 'External handshake blocker missing.' );
$check( empty( $status['authentication']['credential_material_exposed'] ), 'Connection status claims credential material is exposed.' );
$check( empty( $status['authentication']['credential_creation_supported_here'] ), 'Connection status claims credential creation in read-only surface.' );
$check( isset( $status['provider_mcp_isolation'] ) && is_array( $status['provider_mcp_isolation'] ), 'Provider MCP isolation evidence missing from connection status.' );
$check( empty( $status['provider_mcp_isolation']['configured'] ), 'Provider MCP isolation must be OFF by default.' );
$check( empty( $status['provider_mcp_isolation']['effective'] ), 'Provider MCP isolation must be ineffective by default.' );
$check( ! empty( $status['provider_mcp_isolation']['unknown_routes_fail_closed'] ), 'Provider isolation did not report unknown-route fail-closed semantics.' );
$check( empty( $status['provider_mcp_isolation']['changes_provider_settings'] ), 'Provider isolation claims provider settings mutation.' );
$check( empty( $status['provider_mcp_isolation']['creates_authority'] ), 'Provider isolation claims authority creation.' );

$expected = MAD4B_SCP_Servers::expected_server_ids();
$check( count( $status['servers'] ) === count( $expected ), 'MAD4B server count drifted from the server registry.' );
$check( in_array( 'mad4b-chatgpt', $expected, true ), 'mad4b-chatgpt is missing from the governed server registry.' );
$check( in_array( 'mad4b-write', $expected, true ), 'mad4b-write is missing from the governed server registry.' );
$seen = array();
foreach ( $status['servers'] as $server ) {
    $seen[] = $server['server_id'];
    $check( ! empty( $server['registered'] ), 'MAD4B server not registered: ' . $server['server_id'] );
    $check( ! empty( $server['route_registered'] ), 'MAD4B REST route not registered: ' . $server['server_id'] );
    $check( ! empty( $server['permission_callback_match'] ), 'MAD4B transport permission callback mismatch: ' . $server['server_id'] );
    $check( $endpoint_matches_route( $server['endpoint'], $server['server_id'] ), 'Endpoint does not resolve to the expected WordPress REST route for ' . $server['server_id'] . ': ' . $server['endpoint'] );
}
sort( $seen ); sort( $expected );
$check( $seen === $expected, 'MAD4B endpoint inventory mismatch.' );
$check( ! empty( $status['write_surface']['registered'] ), 'Write surface is not registered.' );
$check( ! empty( $status['write_surface']['route_registered'] ), 'Write surface REST route is not registered.' );
$check( ! empty( $status['write_surface']['permission_callback_match'] ), 'Write surface transport permission callback is not exact.' );
$check( ! empty( $status['write_surface']['exact_transport_grant_required'] ), 'Write surface does not report exact transport grant binding.' );
$check( empty( $status['write_surface']['generic_dispatcher_exposed'] ), 'Write surface unexpectedly reports a generic dispatcher.' );
$check( (int) $status['write_surface']['mounted_write_tool_count'] > 0, 'Write surface mounted no explicitly non-readonly tools.' );
$check( $endpoint_matches_route( $status['write_surface']['endpoint'], 'mad4b-write' ), 'Write surface endpoint does not resolve to /mcp/mad4b-write.' );
$check( empty( $status['mcp_peer_governance']['foreign_transport']['detected'] ), 'Clean CI unexpectedly detected a foreign MCP transport.' );
$check( has_action( 'admin_menu', array( 'MAD4B_SCP_Connection_Admin_UI', 'register_menu' ) ) !== false, 'Connection admin submenu hook missing.' );

$tables = MAD4B_SCP_Schema::tables();
global $wpdb;
$before = array(
    (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tables['agents']}" ),
    (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tables['approvals']}" ),
    (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tables['mutations']}" ),
);

$readiness_html = $render_tab( 'readiness' );
$oauth_html = $render_tab( 'oauth' );
$endpoints_html = $render_tab( 'endpoints' );
$isolation_html = $render_tab( 'isolation' );
$certification_html = $render_tab( 'certification' );
$html = $readiness_html . $oauth_html . $endpoints_html . $isolation_html . $certification_html;

$check( false !== strpos( $readiness_html, 'MAD4B Connection' ), 'Connection admin workspace did not render.' );
$check( false !== strpos( $readiness_html, 'Stage 1' ), 'Connection readiness tab omitted staged guidance.' );
$check( false !== strpos( $readiness_html, 'Next step' ), 'Connection readiness tab omitted next-step guidance.' );
$check( false !== strpos( $oauth_html, 'WordPress local OAuth authority' ), 'OAuth tab omitted the standalone WordPress authority.' );
$check( false !== strpos( $oauth_html, 'External / federated OAuth resource bridge' ), 'OAuth tab omitted the external federated authority.' );
$check( false !== strpos( $oauth_html, 'oauth_resource_bridge_not_configured' ), 'OAuth tab omitted OAuth blocker truth.' );
$check( false !== strpos( $endpoints_html, 'mad4b-read' ), 'MCP Endpoints tab omitted the read endpoint.' );
$check( false !== strpos( $endpoints_html, 'mad4b-chatgpt' ), 'MCP Endpoints tab omitted the ChatGPT endpoint.' );
$check( false !== strpos( $endpoints_html, 'mad4b-write' ), 'MCP Endpoints tab omitted the write endpoint.' );
$check( false !== strpos( $endpoints_html, esc_html( $status['write_surface']['endpoint'] ) ), 'MCP Endpoints tab did not render the runtime-derived write endpoint.' );
$check( false !== strpos( $endpoints_html, 'Governed write ingress' ), 'MCP Endpoints tab omitted governed write readiness.' );
$check( false !== strpos( $isolation_html, 'Provider MCP isolation' ), 'Isolation tab omitted provider isolation evidence.' );
$check( false !== strpos( $isolation_html, 'Unknown routes fail closed' ), 'Isolation tab omitted provider isolation fail-closed truth.' );
$check( false !== strpos( $isolation_html, 'External Adapter peers' ), 'Isolation tab omitted bounded external Adapter peer evidence.' );
$check( false !== strpos( $certification_html, 'external_handshake_unverified' ), 'Certification tab omitted external-handshake truth.' );
$check( false !== strpos( $certification_html, 'Required evidence' ), 'Certification tab omitted explicit evidence guidance.' );
foreach ( array( 'client_secret', 'access_token', 'refresh_token', 'authorization_header', 'rollback_payload' ) as $secret ) $check( false === stripos( $html, $secret ), 'Connection admin workspace exposed forbidden material: ' . $secret );

$after = array(
    (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tables['agents']}" ),
    (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tables['approvals']}" ),
    (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tables['mutations']}" ),
);
$check( $before === $after, 'Read-only staged connection rendering changed governance state.' );

echo "mad4b.site-control-plane.runtime-connection-readiness.v7: PASS\n";
