<?php
/** Runtime proof for read-only local connection truth and staged admin rendering. */
if ( ! defined( 'ABSPATH' ) ) throw new RuntimeException( 'WordPress is not loaded.' );
require_once __DIR__ . '/prepared-dispatch-runtime-helper.php';
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
    $had_page = array_key_exists( 'page', $_GET );
    $previous_page = $had_page ? $_GET['page'] : null;
    $_GET['page'] = 'mad4b-control-plane-connection';
    $_GET['tab'] = $tab;
    ob_start();
    MAD4B_SCP_Connection_Admin_UI::render_page();
    $html = ob_get_clean();
    if ( $had_tab ) $_GET['tab'] = $previous_tab;
    else unset( $_GET['tab'] );
    if ( $had_page ) $_GET['page'] = $previous_page;
    else unset( $_GET['page'] );
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
$check( class_exists( 'MAD4B_SCP_Read_Consistency' ), 'Read consistency class unavailable.' );
$check( class_exists( 'MAD4B_SCP_MCP_MU_Bootstrap_Refresh' ), 'MCP MU transaction gate unavailable.' );
$check( class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' ), 'OAuth resource bridge unavailable for transaction quarantine proof.' );

// A normal WordPress request may load the certified Adapter after MU phase. The
// durable transaction gate must still quarantine both MCP transport and REST
// aliases when the journal is malformed or pending.
$tx_option = MAD4B_SCP_MCP_MU_Bootstrap_Refresh::TRANSACTION_OPTION;
$tx_original = get_option( $tx_option, null );
foreach ( array(
	'malformed' => 'corrupt-scalar',
	'pending' => array(
		'contract' => MAD4B_SCP_MCP_MU_Bootstrap_Refresh::TRANSACTION_CONTRACT,
		'transaction_id' => str_repeat( 'a', 32 ),
		'state' => 'prepared',
		'operation' => 'install',
		'previous_sha256' => '',
		'target_sha256' => str_repeat( 'b', 64 ),
		'created_at' => time(),
	),
) as $tx_case => $tx_value ) {
	update_option( $tx_option, $tx_value, false );
	if ( function_exists( 'wp_cache_delete' ) ) { wp_cache_delete( $tx_option, 'options' ); wp_cache_delete( 'notoptions', 'options' ); }
	$transport_request = new WP_REST_Request( 'POST', '/mcp/mad4b-chatgpt' );
	$transport_denied = MAD4B_SCP_Servers::can_chatgpt_transport( $transport_request );
	$expected_code = 'malformed' === $tx_case ? 'mu_bootstrap_transaction_invalid' : 'mu_bootstrap_transaction_pending';
	$check( is_wp_error( $transport_denied ) && $expected_code === $transport_denied->get_error_code(), $tx_case . ' transaction did not quarantine MCP transport after normal plugin load.' );

	wp_set_current_user( 0 );
	$alias_request = new WP_REST_Request( 'GET', '/mad4b/v1/ability-catalog/capabilities' );
	$alias_denied = MAD4B_SCP_OAuth_Resource_Bridge::authenticate_rest_request( null, rest_get_server(), $alias_request );
	$check( $alias_denied instanceof WP_REST_Response && 503 === (int) $alias_denied->get_status(), $tx_case . ' transaction did not quarantine protected REST alias.' );
}
if ( null === $tx_original ) delete_option( $tx_option ); else update_option( $tx_option, $tx_original, false );
if ( function_exists( 'wp_cache_delete' ) ) { wp_cache_delete( $tx_option, 'options' ); wp_cache_delete( 'notoptions', 'options' ); }


$check( function_exists( 'wp_has_ability' ) && wp_has_ability( 'mad4b/session-safe-diagnostics' ), 'Session-safe diagnostics ability is not registered.' );
$check( function_exists( 'wp_has_ability' ) && wp_has_ability( 'mad4b/read-snapshot-header' ), 'Read snapshot header ability is not registered.' );
$check( function_exists( 'wp_has_ability' ) && wp_has_ability( 'mad4b/read-diagnostic-bundle' ), 'Read diagnostic bundle ability is not registered.' );
$check( function_exists( 'wp_has_ability' ) && wp_has_ability( 'mad4b/read-metadata-envelope' ), 'Read metadata envelope ability is not registered.' );
$check( MAD4B_SCP_Servers::ability_is_mounted( 'mad4b-read', 'mad4b/session-safe-diagnostics' ), 'Session-safe diagnostics are not mounted on mad4b-read.' );
$check( MAD4B_SCP_Servers::ability_is_mounted( 'mad4b-chatgpt', 'mad4b/session-safe-diagnostics' ), 'Session-safe diagnostics are not mounted on mad4b-chatgpt.' );
$check( MAD4B_SCP_Servers::ability_is_mounted( 'mad4b-read', 'mad4b/read-snapshot-header' ), 'Read snapshot header is not mounted on mad4b-read.' );
$check( ! MAD4B_SCP_Servers::ability_is_mounted( 'mad4b-chatgpt', 'mad4b/read-snapshot-header' ), 'Read snapshot header must remain hidden from the compact ChatGPT direct tool list.' );
$check( MAD4B_SCP_Servers::ability_is_mounted( 'mad4b-read', 'mad4b/read-diagnostic-bundle' ), 'Read diagnostic bundle is not mounted on mad4b-read.' );
$check( ! MAD4B_SCP_Servers::ability_is_mounted( 'mad4b-chatgpt', 'mad4b/read-diagnostic-bundle' ), 'Read diagnostic bundle must remain hidden from the compact ChatGPT direct tool list.' );
$check( MAD4B_SCP_Servers::ability_is_mounted( 'mad4b-read', 'mad4b/read-metadata-envelope' ), 'Read metadata envelope is not mounted on mad4b-read.' );
$check( ! MAD4B_SCP_Servers::ability_is_mounted( 'mad4b-chatgpt', 'mad4b/read-metadata-envelope' ), 'Read metadata envelope must remain hidden from the compact ChatGPT direct tool list.' );
$check( MAD4B_SCP_Servers::is_chatgpt_full_catalog_candidate( 'mad4b/read-snapshot-header' ), 'Read snapshot header is not reachable through governed read dispatch.' );
$check( MAD4B_SCP_Servers::is_chatgpt_full_catalog_candidate( 'mad4b/read-diagnostic-bundle' ), 'Read diagnostic bundle is not reachable through governed read dispatch.' );
$check( MAD4B_SCP_Servers::is_chatgpt_full_catalog_candidate( 'mad4b/read-metadata-envelope' ), 'Read metadata envelope is not reachable through governed read dispatch.' );

/*
 * Behavioral proof for the real 504 incident surface. These guards observe the
 * actual WordPress query/HTTP hooks while Connection and ChatGPT admin status is
 * evaluated; source-marker coverage alone is not sufficient for this regression.
 */
$hotpath_previous_page_exists = array_key_exists( 'page', $_GET );
$hotpath_previous_page = $hotpath_previous_page_exists ? $_GET['page'] : null;
$hotpath_had_current_screen = isset( $GLOBALS['current_screen'] );
$hotpath_previous_screen = $hotpath_had_current_screen ? $GLOBALS['current_screen'] : null;
if ( ! function_exists( 'set_current_screen' ) ) require_once ABSPATH . 'wp-admin/includes/screen.php';
set_current_screen( 'dashboard' );
$check( is_admin(), '504 hotpath runtime proof could not establish WordPress admin context.' );
$check( class_exists( 'MAD4B_SCP_MCP_Request_Scope' ), 'MCP request scope class unavailable.' );

$check( MAD4B_SCP_MCP_Request_Scope::passive_admin_route_for_test( 'mad4b-control-plane-chatgpt' ), 'ChatGPT admin route was not classified as passive.' );
$check( MAD4B_SCP_MCP_Request_Scope::passive_admin_route_for_test( 'mad4b-control-plane-connection', 'readiness' ), 'Connection readiness route was not classified as passive.' );
foreach ( array( 'oauth', 'isolation', 'certification', 'endpoints' ) as $passive_tab ) {
    $check( MAD4B_SCP_MCP_Request_Scope::passive_admin_route_for_test( 'mad4b-control-plane-connection', $passive_tab ), 'Passive Connection route was not classified as shallow: ' . $passive_tab );
}
$check( MAD4B_SCP_MCP_Request_Scope::passive_admin_route_for_test( 'mad4b-control-plane-skills' ), 'Skills GET route must remain request-serving/passive.' );
$check( MAD4B_SCP_MCP_Request_Scope::passive_admin_route_for_test( 'mad4b-control-plane' ), 'Control Plane overview GET route must remain request-serving/passive.' );

// The runtime smoke executes through WP-CLI, where full MCP runtime remains
// intentionally available regardless of simulated admin routing.
$check( MAD4B_SCP_MCP_Request_Scope::current_request_requires_mcp_runtime(), 'WP-CLI lost its intentional full MCP runtime override.' );

$_GET['page'] = 'mad4b-control-plane-connection';
$hotpath_oauth_table_queries = array();
$hotpath_cimd_fetches = 0;
$hotpath_query_watch = static function ( $sql ) use ( &$hotpath_oauth_table_queries ) {
    $sql_text = (string) $sql;
    if ( false !== stripos( $sql_text, 'SHOW TABLES' )
        && ( false !== stripos( $sql_text, 'mad4b_scp_oauth_codes' )
            || false !== stripos( $sql_text, 'mad4b_scp_oauth_refresh_tokens' ) ) ) {
        $hotpath_oauth_table_queries[] = $sql_text;
    }
    return $sql;
};
$hotpath_http_watch = static function ( $preempt, $args, $url ) use ( &$hotpath_cimd_fetches ) {
    unset( $args );
    if ( 'https://chatgpt.com/oauth/client.json' === untrailingslashit( (string) $url ) ) {
        $hotpath_cimd_fetches++;
        return new WP_Error( 'mad4b_test_unexpected_cimd_fetch', 'CIMD fetch is forbidden on admin hotpath.' );
    }
    return $preempt;
};
add_filter( 'query', $hotpath_query_watch, PHP_INT_MAX, 1 );
add_filter( 'pre_http_request', $hotpath_http_watch, PHP_INT_MIN, 3 );

$_GET['page'] = 'mad4b-control-plane-connection';
$admin_shallow = MAD4B_SCP_Connection_Status::status();
$check( is_array( $admin_shallow ) && 'admin_shallow' === ( isset( $admin_shallow['status_mode'] ) ? $admin_shallow['status_mode'] : '' ), 'Connection admin status did not select admin_shallow mode.' );
$check( ! empty( $admin_shallow['transport_deep_validation_deferred'] ), 'Connection admin status unexpectedly performed deep transport validation.' );
$check( isset( $admin_shallow['local_transport_validation_state'] ) && in_array( $admin_shallow['local_transport_validation_state'], array( 'identity_ready_deep_validation_deferred', 'ready' ), true ), 'Connection admin shallow transport did not expose a truthful tri-state validation state.' );
$check( ! in_array( 'mad4b_transport_registration_incomplete', (array) $admin_shallow['local_blockers'], true ), 'Connection admin shallow status converted deferred MCP registration into a false transport blocker.' );
$check( isset( $admin_shallow['connection_certification_state'] ) && false !== strpos( (string) $admin_shallow['connection_certification_state'], 'deferred' ), 'Connection admin shallow certification must be explicitly deferred rather than falsely certified/not-certified.' );
$check( in_array( 'provider_runtime_integrity', isset( $admin_shallow['deferred_checks'] ) ? (array) $admin_shallow['deferred_checks'] : array(), true ), 'Connection admin status did not defer provider runtime integrity.' );
$check( isset( $admin_shallow['mcp_adapter_certification']['runtime_integrity_verification_deferred'] ) && ! empty( $admin_shallow['mcp_adapter_certification']['runtime_integrity_verification_deferred'] ), 'Connection admin status did not use the identity-only provider projection.' );
$peer_admin = isset( $admin_shallow['mcp_peer_governance'] ) && is_array( $admin_shallow['mcp_peer_governance'] ) ? $admin_shallow['mcp_peer_governance'] : array();
$check( 'deferred_admin_hotpath' === ( isset( $peer_admin['state'] ) ? $peer_admin['state'] : '' ), 'Connection admin status did not defer peer inventory.' );
$check( empty( $peer_admin['deep_inventory_performed'] ), 'Connection admin status performed deep peer inventory.' );

$admin_convergence_gate = new ReflectionMethod( 'MAD4B_SCP_Runtime_Convergence', 'admin_page_convergence_allowed' );
$admin_convergence_gate->setAccessible( true );
$check(
    false === $admin_convergence_gate->invoke( null, 'mad4b-control-plane-connection', 'admin.php', '' ),
    'Connection admin page remained a Runtime Convergence trigger.'
);

$_GET['page'] = 'mad4b-control-plane-chatgpt';
if ( class_exists( 'MAD4B_SCP_ChatGPT_Connection_Admin_UI' ) ) {
    $chatgpt_cache = new ReflectionProperty( 'MAD4B_SCP_ChatGPT_Connection_Admin_UI', 'status_cache' );
    $chatgpt_cache->setAccessible( true );
    $chatgpt_cache->setValue( null, null );
    $chatgpt_admin = MAD4B_SCP_ChatGPT_Connection_Admin_UI::status();
    $check( 'runtime_identity' === ( isset( $chatgpt_admin['admin_status_projection'] ) ? $chatgpt_admin['admin_status_projection'] : '' ), 'ChatGPT admin status did not use the runtime identity projection.' );
    $check( ! empty( $chatgpt_admin['deep_oauth_status_deferred'] ), 'ChatGPT admin status did not defer deep OAuth diagnostics.' );
    $check( array_key_exists( 'gateway_registration_identity_ready', $chatgpt_admin ), 'ChatGPT admin status did not expose registration identity readiness separately from actual registration.' );
    $check( array_key_exists( 'gateway_registration_deep_check_deferred', $chatgpt_admin ), 'ChatGPT admin status did not expose deferred registration validation.' );
    $check( array_key_exists( 'external_connection_evidence_present', $chatgpt_admin ), 'ChatGPT admin status did not expose persisted external evidence truth.' );
}
$check(
    false === $admin_convergence_gate->invoke( null, 'mad4b-control-plane-chatgpt', 'admin.php', '' ),
    'ChatGPT admin page remained a Runtime Convergence trigger.'
);

if ( class_exists( 'MAD4B_SCP_Reconnect_Hardening' ) ) {
    $reconnect_admin = MAD4B_SCP_Reconnect_Hardening::reconnect_status();
    $check( 'runtime_identity' === ( isset( $reconnect_admin['reconnect_status_projection'] ) ? $reconnect_admin['reconnect_status_projection'] : '' ), 'Reconnect readiness did not use the runtime identity projection.' );
    $check( ! empty( $reconnect_admin['deep_oauth_status_deferred'] ), 'Reconnect readiness did not defer deep OAuth status.' );
}

remove_filter( 'query', $hotpath_query_watch, PHP_INT_MAX );
remove_filter( 'pre_http_request', $hotpath_http_watch, PHP_INT_MIN );
if ( $hotpath_previous_page_exists ) $_GET['page'] = $hotpath_previous_page;
else unset( $_GET['page'] );
if ( $hotpath_had_current_screen ) $GLOBALS['current_screen'] = $hotpath_previous_screen;
else unset( $GLOBALS['current_screen'] );

$check( empty( $hotpath_oauth_table_queries ), 'Connection/ChatGPT admin hotpath executed physical OAuth SHOW TABLES probes: ' . wp_json_encode( $hotpath_oauth_table_queries ) );
$check( 0 === $hotpath_cimd_fetches, 'Connection/ChatGPT admin hotpath attempted ChatGPT CIMD network discovery.' );

$snapshot = MAD4B_SCP_Read_Consistency::snapshot_header( array() );
$check( isset( $snapshot['contract'] ) && 'mad4b.read-consistency.v1' === $snapshot['contract'], 'Read snapshot contract drifted.' );
$check( isset( $snapshot['runtime_generation'] ) && 1 === preg_match( '/^[a-f0-9]{64}$/', $snapshot['runtime_generation'] ), 'Read snapshot runtime generation missing.' );
$check( isset( $snapshot['read_transaction_id'] ) && 0 === strpos( $snapshot['read_transaction_id'], 'rtx_' ), 'Read transaction id missing.' );
$check( ! empty( $snapshot['read_only'] ) && empty( $snapshot['mutation_performed'] ), 'Read snapshot must remain mutation-free.' );
$bundle = MAD4B_SCP_Read_Consistency::diagnostic_bundle( array(
    'bundle' => 'identity',
    'read_transaction_id' => $snapshot['read_transaction_id'],
    'expected_runtime_generation' => $snapshot['runtime_generation'],
    'sequence' => 1,
    'budget_ms' => 5000,
) );
$check( is_array( $bundle ) && ! empty( $bundle['generation_match'] ), 'Same-generation identity bundle did not remain mergeable.' );
$check( ! empty( $bundle['valid_for_merge'] ) && empty( $bundle['discard_partial'] ), 'Same-generation identity bundle was incorrectly discarded.' );
$mismatch = MAD4B_SCP_Read_Consistency::diagnostic_bundle( array(
    'bundle' => 'identity',
    'read_transaction_id' => $snapshot['read_transaction_id'],
    'expected_runtime_generation' => str_repeat( '0', 64 ),
    'sequence' => 2,
) );
$check( is_array( $mismatch ) && 'generation_changed' === $mismatch['state'], 'Generation mismatch did not fail closed.' );
$check( empty( $mismatch['valid_for_merge'] ) && ! empty( $mismatch['discard_partial'] ) && empty( $mismatch['resume_permitted'] ), 'Generation mismatch did not invalidate partial evidence.' );
$read_dispatch = wp_get_ability( 'mad4b/read-execute' );
$read_info = wp_get_ability( 'mad4b/tool-info' );
$check( is_object( $read_dispatch ) && method_exists( $read_dispatch, 'execute' ), 'Governed read dispatcher is unavailable.' );
$check( is_object( $read_info ) && method_exists( $read_info, 'execute' ), 'Governed read info tool is unavailable.' );
$metadata_info = $read_info->execute( array( 'ability_name' => 'mad4b/read-metadata-envelope' ) );
$check( ! is_wp_error( $metadata_info ) && ! empty( $metadata_info['input_schema_sha256'] ), 'Compact metadata schema digest is unavailable.' );
$metadata_identity = mad4b_test_prepared_dispatch_identity( 'mad4b/read-metadata-envelope' );
$check( ! is_wp_error( $metadata_identity ), 'Compact metadata signed preparation is unavailable.' );
$metadata_dispatch = $read_dispatch->execute( array_merge(
    array(
        'ability_name' => 'mad4b/read-metadata-envelope',
        'input' => array(
            'target_type' => 'operation',
            'target' => 'managed_skills_reconciliation',
            'read_transaction_id' => $snapshot['read_transaction_id'],
            'expected_runtime_generation' => $snapshot['runtime_generation'],
        ),
    ),
    $metadata_identity
) );
$check( ! is_wp_error( $metadata_dispatch ), 'Compact metadata dispatch returned an error: ' . ( is_wp_error( $metadata_dispatch ) ? $metadata_dispatch->get_error_code() : '' ) );
$check( is_array( $metadata_dispatch ) && 'mad4b.chatgpt-read-execute.v1' === $metadata_dispatch['contract'], 'Compact metadata dispatch wrapper drifted.' );
$metadata = isset( $metadata_dispatch['result'] ) && is_array( $metadata_dispatch['result'] ) ? $metadata_dispatch['result'] : array();
$check( is_array( $metadata ) && 'ready' === $metadata['state'], 'Compact operation metadata envelope was not ready through governed read dispatch.' );
$check( ! empty( $metadata['generation_match'] ) && ! empty( $metadata['valid_for_resume'] ), 'Compact metadata envelope lost generation binding.' );
$check( 'mad4b/reconcile-managed-skills' === $metadata['remote_ability'], 'Compact metadata envelope resolved the wrong remote ability.' );
$check( isset( $metadata['registration_digest'] ) && 1 === preg_match( '/^[a-f0-9]{64}$/', $metadata['registration_digest'] ), 'Operation registration digest missing from compact metadata envelope.' );
$check( isset( $metadata['dispatch_policy_digest'] ) && 1 === preg_match( '/^[a-f0-9]{64}$/', $metadata['dispatch_policy_digest'] ), 'Enrollment dispatch-policy digest missing from compact metadata envelope.' );
$check( ! empty( $metadata['input_schema_available'] ) && 1 === preg_match( '/^[a-f0-9]{64}$/', $metadata['input_schema_sha256'] ), 'Input schema digest missing from compact metadata envelope.' );
$canonical_dispatch_info = MAD4B_SCP_Enrollment_Dispatch::info( array( 'operation_id' => 'managed_skills_reconciliation' ) );
$check( ! is_wp_error( $canonical_dispatch_info ) && is_array( $canonical_dispatch_info ), 'Canonical enrollment dispatch metadata is unavailable.' );
$check( hash_equals( strtolower( (string) $canonical_dispatch_info['registration_digest'] ), $metadata['registration_digest'] ), 'Compact metadata registration digest diverged from enrollment dispatch.' );
$check( hash_equals( strtolower( (string) $canonical_dispatch_info['dispatch_policy_digest'] ), $metadata['dispatch_policy_digest'] ), 'Compact metadata dispatch-policy digest diverged from enrollment dispatch.' );
$check( hash_equals( strtolower( (string) $canonical_dispatch_info['input_schema_sha256'] ), $metadata['input_schema_sha256'] ), 'Compact metadata input schema digest diverged from enrollment dispatch.' );
$check( isset( $metadata['execution_binding_digest'] ) && 1 === preg_match( '/^[a-f0-9]{64}$/', $metadata['execution_binding_digest'] ), 'Execution binding digest missing from compact metadata envelope.' );
$denied_metadata = MAD4B_SCP_Read_Consistency::metadata_envelope( array(
    'target_type' => 'ability',
    'target' => 'mad4b/database-raw-query',
    'read_transaction_id' => $snapshot['read_transaction_id'],
    'expected_runtime_generation' => $snapshot['runtime_generation'],
) );
$check( is_wp_error( $denied_metadata ) && 'mad4b_metadata_ability_not_cataloged' === $denied_metadata->get_error_code(), 'Compact metadata envelope exposed an ability outside the governed ChatGPT catalog.' );
$guidance = MAD4B_SCP_Connector_Resilience::client_guidance();
$check( 1 === (int) $guidance['preferred_parallelism'] && 2 === (int) $guidance['read_parallelism_max'], 'Read parallelism contract drifted.' );
$check( 1 === (int) $guidance['reconnect_attempts'] && ! empty( $guidance['replay_read_after_reconnect'] ) && empty( $guidance['replay_mutation_after_reconnect'] ), 'Reconnect/replay contract drifted.' );
$check( ! empty( $guidance['snapshot_identity_required'] ) && ! empty( $guidance['discard_partial_on_generation_change'] ), 'Snapshot-aware recovery contract drifted.' );
$check( ! empty( $guidance['metadata_micro_read_preferred'] ) && 'mad4b/read-metadata-envelope' === $guidance['metadata_envelope_ability'], 'Compact metadata recovery guidance drifted.' );
$check( ! empty( $guidance['resume_after_reconnect_requires_generation_match'] ), 'Reconnect resume must remain generation-bound.' );

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
$portable_ready = class_exists( 'MAD4B_SCP_Portable_Readonly_Connection' ) && MAD4B_SCP_Portable_Readonly_Connection::effective();
$is_https_target = 'https' === strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_SCHEME ) );
$check( isset( $status['portable_readonly_ready'] ) && (bool) $status['portable_readonly_ready'] === (bool) $portable_ready, 'Portable read-only connection truth did not project into connection readiness.' );
$check( $is_https_target ? ! in_array( 'https_required_for_remote_mcp', $status['remote_preflight_blockers'], true ) : in_array( 'https_required_for_remote_mcp', $status['remote_preflight_blockers'], true ), 'HTTPS remote blocker did not match the disposable target scheme.' );
$check( isset( $status['oauth_resource_server'] ) && is_array( $status['oauth_resource_server'] ), 'OAuth resource-server truth missing from connection status.' );
if ( $portable_ready && $is_https_target ) {
    $check( ! empty( $status['remote_endpoint_preflight_ready'] ), 'Portable read-only connection did not make the HTTPS remote endpoint preflight-ready.' );
    foreach ( array( 'oauth_resource_bridge_not_configured', 'oauth_issuer_unconfigured', 'oauth_wp_subject_unconfigured', 'oauth_environment_not_allowed' ) as $blocker ) {
        $check( ! in_array( $blocker, $status['remote_preflight_blockers'], true ), 'Portable read-only connection retained obsolete OAuth blocker: ' . $blocker );
    }
    $check( ! empty( $status['oauth_resource_server']['configured'] ), 'Portable connection did not configure the OAuth resource bridge.' );
    $check( ! empty( $status['oauth_resource_server']['effective'] ), 'Portable connection did not make the OAuth resource bridge effective.' );
    $check( ! empty( $status['oauth_resource_server']['preflight_ready'] ), 'Portable connection did not make OAuth preflight ready.' );
    $check( empty( $status['oauth_resource_server']['write_surfaces_enabled'] ), 'Portable connection must not enable write surfaces.' );
} else {
    $check( empty( $status['remote_endpoint_preflight_ready'] ), 'Non-portable or non-HTTPS runtime must not claim remote endpoint preflight readiness.' );
}
$check( empty( $status['connection_certified'] ), 'Repository/local inspection must never self-certify the external connection.' );
$check( empty( $status['external_handshake']['verified'] ), 'External handshake was incorrectly marked verified.' );
$check( 'unverified' === $status['external_handshake']['status'], 'Absent durable external evidence must remain explicitly unverified.' );
$check( in_array( 'external_handshake_unverified', $status['certification_blockers'], true ), 'External handshake blocker missing.' );
$check( empty( $status['authentication']['credential_material_exposed'] ), 'Connection status claims credential material is exposed.' );
$check( empty( $status['authentication']['credential_creation_supported_here'] ), 'Connection status claims credential creation in read-only surface.' );
$check( isset( $status['provider_mcp_isolation'] ) && is_array( $status['provider_mcp_isolation'] ), 'Provider MCP isolation evidence missing from connection status.' );
$provider_isolation_runtime = class_exists( 'MAD4B_SCP_MCP_Provider_Isolation' ) ? MAD4B_SCP_MCP_Provider_Isolation::status() : array();
$check( is_array( $provider_isolation_runtime ) && ! empty( $provider_isolation_runtime['contract'] ), 'Provider MCP isolation runtime evidence is unavailable.' );
$check( (bool) $status['provider_mcp_isolation']['configured'] === (bool) $provider_isolation_runtime['configured'], 'Connection projection drifted from Provider MCP isolation configured truth.' );
$check( (bool) $status['provider_mcp_isolation']['effective'] === (bool) $provider_isolation_runtime['effective'], 'Connection projection drifted from Provider MCP isolation effective truth.' );
if ( $portable_ready && $is_https_target ) {
    $check( ! empty( $status['provider_mcp_isolation']['configured'] ), 'Portable Staging bootstrap did not configure deny-only Provider MCP isolation.' );
    $check( ! empty( $status['provider_mcp_isolation']['effective'] ), 'Portable Staging bootstrap did not make deny-only Provider MCP isolation effective.' );
    $check( ! empty( $provider_isolation_runtime['runtime_suppression_approved'] ), 'Portable Staging isolation did not synthesize the bounded runtime-suppression gate.' );
    $check( ! empty( $provider_isolation_runtime['staging_zero_touch_autoconfig_applied'] ), 'Portable Staging isolation did not report zero-touch autoconfiguration.' );
    $check( 'portable_readonly_bootstrap' === (string) $provider_isolation_runtime['staging_zero_touch_autoconfig_source'], 'Portable Staging isolation reported the wrong autoconfiguration source.' );
} else {
    $check( empty( $status['provider_mcp_isolation']['configured'] ), 'Provider MCP isolation unexpectedly configured without an eligible portable Staging bootstrap.' );
    $check( empty( $status['provider_mcp_isolation']['effective'] ), 'Provider MCP isolation unexpectedly became effective without an eligible portable Staging bootstrap.' );
}
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
if ( $portable_ready && $is_https_target ) {
    $check( false === strpos( $oauth_html, 'oauth_resource_bridge_not_configured' ), 'OAuth tab retained an obsolete not-configured blocker in portable mode.' );
} else {
    $check( false !== strpos( $oauth_html, 'oauth_resource_bridge_not_configured' ), 'OAuth tab omitted OAuth blocker truth.' );
}
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
