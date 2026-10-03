<?php
/** Real WordPress HTTP-style bootstrap: WP_CLI must be absent. */
$path = getenv( 'MAD4B_TEST_WP_PATH' );
if ( ! is_string( $path ) || ! is_file( $path . '/wp-load.php' ) ) exit( 1 );
define( 'WP_ADMIN', true ); define( 'DOING_AJAX', true ); define( 'DISABLE_WP_CRON', true );
$_SERVER['REQUEST_URI']='/wp-admin/admin-ajax.php';
$_SERVER['REQUEST_METHOD']='POST';
$_SERVER['HTTP_HOST']='mad4b-runtime.test';
$mu_proof = getenv( 'MAD4B_TEST_MU_PROOF' );
if ( ! is_string( $mu_proof ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $mu_proof ) ) {
	fwrite( STDERR, "FAIL: missing bounded diagnostic MU routing proof\n" );
	exit( 1 );
}
$_POST=array( 'action'=>'mad4b_connection_endpoint_diagnostic', 'server_id'=>'mad4b-read', 'mu_proof'=>$mu_proof );
$_REQUEST=$_POST;
require $path . '/wp-load.php';
function ajax_recovery_check( $ok, $message, $data = null ) {
	if ( ! $ok ) { fwrite( STDERR, 'FAIL: ' . $message . ' ' . wp_json_encode( $data ) . PHP_EOL ); exit( 1 ); }
}
$mu=$GLOBALS['mad4b_scp_mcp_mu_bootstrap'] ?? array();
ajax_recovery_check( ! defined( 'WP_CLI' ), 'Fixture must exercise the HTTP lifecycle.' );
ajax_recovery_check( 'production'===wp_get_environment_type() && 'staging'===MAD4B_SCP_Site_Profile::current_environment(), 'Implicit Production must use exact profile Staging.' );
ajax_recovery_check( ! empty( $mu['critical_class_set_pinned'] ) && 11===($mu['critical_class_pin_count'] ?? 0), 'AJAX request did not pin certified classes before the foreign loader.', $mu );
ajax_recovery_check( 'canonical_runtime_pinned_diagnostic_deferred'===($mu['state'] ?? '') && empty( $mu['adapter_instance_armed'] ), 'MU diagnostic must defer singleton arming to the authorized worker.', $mu );
ajax_recovery_check( 'mad4b-read'===($mu['diagnostic_server_id'] ?? ''), 'MU diagnostic did not bind the exact read target.', $mu );
ajax_recovery_check( function_exists( 'wp_has_ability' ) && wp_has_ability( 'mad4b/adapters-inventory' ), 'Provider-backed adapter registry was absent during the canonical Abilities lifecycle.' );
ajax_recovery_check( wp_has_ability( 'elementor/status' ), 'Provider adapter abilities were not registered before worker authorization.' );
ajax_recovery_check( 0===did_action( 'rest_api_init' ), 'REST was materialized before worker authorization.' );
$user=get_user_by( 'login', 'mad4b-ci-admin' ); wp_set_current_user( $user->ID );
$_POST += array( 'nonce'=>wp_create_nonce( 'mad4b_connection_deep_endpoints' ), 'build'=>MAD4B_SCP_Endpoint_Diagnostic::build_fingerprint() );
$destination=WPMU_PLUGIN_DIR . '/000-mad4b-mcp-adapter-bootstrap.php';
$before=hash_file( 'sha256', $destination );
$active=get_option( 'active_plugins' );
$job=MAD4B_SCP_Endpoint_Diagnostic::run();
ajax_recovery_check( ! is_wp_error( $job ), 'Authorized AJAX diagnostic failed.', is_wp_error( $job ) ? $job->get_error_code() : null );
$server=$job['server'];
ajax_recovery_check( ! empty( $job['runtime_bootstrap']['critical_class_set_pinned'] ) && 11===($job['runtime_bootstrap']['critical_class_pin_count'] ?? 0) && ! empty( $job['runtime_bootstrap']['runtime_from_official_plugin'] ), 'Recovered official class provenance was not retained by the diagnostic.', $job['runtime_bootstrap'] ?? array() );
ajax_recovery_check( ! empty( $server['registered'] ) && ! empty( $server['route_registered'] ) && ! empty( $server['permission_callback_match'] ), 'Read endpoint registration was not exact.', $server );
ajax_recovery_check( true===($server['catalog_count_match'] ?? null) && ($server['observed_tool_count'] ?? 0)===($server['catalog_tool_count'] ?? -1), 'Read catalog materialization count drifted from the registered candidate inventory.', $server );
ajax_recovery_check( ! empty( $server['local_endpoint_ready'] ) && ($server['tool_count'] ?? 0) > 40, 'Real provider-backed read AJAX catalog did not recover.', $server );
ajax_recovery_check( $before===hash_file( 'sha256', $destination ) && $active===get_option( 'active_plugins' ), 'Read-only diagnostic changed runtime bytes or plugin inventory.' );
ajax_recovery_check( empty( $job['connection_certified'] ), 'Local recovery cannot certify an external OAuth handshake.' );
echo 'mad4b.mcp-mixed-class-ajax-recovered.v1: PASS' . PHP_EOL;
