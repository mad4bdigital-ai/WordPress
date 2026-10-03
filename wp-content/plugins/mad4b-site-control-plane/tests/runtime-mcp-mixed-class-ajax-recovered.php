<?php
/** Real WordPress HTTP-style bootstrap: WP_CLI must be absent. */
$path = getenv( 'MAD4B_TEST_WP_PATH' );
if ( ! is_string( $path ) || ! is_file( $path . '/wp-load.php' ) ) exit( 1 );
define( 'WP_ADMIN', true ); define( 'DOING_AJAX', true ); define( 'DISABLE_WP_CRON', true );
$_SERVER['REQUEST_URI']='/wp-admin/admin-ajax.php';
$_SERVER['REQUEST_METHOD']='POST';
$_SERVER['HTTP_HOST']='mad4b-runtime.test';
$_POST=array( 'action'=>'mad4b_connection_endpoint_diagnostic' );
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
ajax_recovery_check( 0===did_action( 'rest_api_init' ), 'REST was materialized before worker authorization.' );
$user=get_user_by( 'login', 'mad4b-ci-admin' ); wp_set_current_user( $user->ID );
$_POST += array( 'nonce'=>wp_create_nonce( 'mad4b_connection_deep_endpoints' ), 'server_id'=>'mad4b-chatgpt', 'build'=>MAD4B_SCP_Endpoint_Diagnostic::build_fingerprint() );
$destination=WPMU_PLUGIN_DIR . '/000-mad4b-mcp-adapter-bootstrap.php';
$before=hash_file( 'sha256', $destination );
$active=get_option( 'active_plugins' );
$job=MAD4B_SCP_Endpoint_Diagnostic::run();
ajax_recovery_check( ! is_wp_error( $job ), 'Authorized AJAX diagnostic failed.', is_wp_error( $job ) ? $job->get_error_code() : null );
$server=$job['server'];
ajax_recovery_check( ! empty( $server['runtime_class_provenance_ready'] ) && 'certified_class_set'===($server['runtime_class_provenance_state'] ?? '') && 0===($server['runtime_class_failure_count'] ?? -1), 'Recovered class provenance was not certified.', $server );
ajax_recovery_check( ! empty( $server['catalog_ready'] ) && ! empty( $server['preflight_ready'] ) && 26===($server['tool_count'] ?? 0), 'Real AJAX catalog did not recover.', $server );
ajax_recovery_check( $before===hash_file( 'sha256', $destination ) && $active===get_option( 'active_plugins' ), 'Read-only diagnostic changed runtime bytes or plugin inventory.' );
ajax_recovery_check( empty( $job['connection_certified'] ), 'Local recovery cannot certify an external OAuth handshake.' );
echo 'mad4b.mcp-mixed-class-ajax-recovered.v1: PASS' . PHP_EOL;
