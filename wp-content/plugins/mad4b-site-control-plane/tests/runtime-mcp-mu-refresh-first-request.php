<?php
/** First non-WPCLI request after installing a newer Control Plane over stale managed MU state. */

$wp_path = getenv( 'MAD4B_TEST_WP_PATH' );
if ( ! is_string( $wp_path ) || '' === trim( $wp_path ) ) {
	fwrite( STDERR, "FAIL mcp-mu-refresh-first-request: MAD4B_TEST_WP_PATH is required\n" );
	exit( 1 );
}
$wp_path = rtrim( $wp_path, '/\\' );
$_SERVER['HTTP_HOST'] = 'staging.egypttourgates.com';
$_SERVER['HTTPS'] = 'on';
$_SERVER['SERVER_PORT'] = '443';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/wp-admin/admin.php?page=mad4b-control-plane-connection&tab=endpoints';
require $wp_path . '/wp-load.php';

$fail = static function ( $message ) {
	fwrite( STDERR, 'FAIL mcp-mu-refresh-first-request: ' . $message . PHP_EOL );
	exit( 1 );
};

if ( defined( 'WP_CLI' ) && WP_CLI ) $fail( 'process unexpectedly entered WP-CLI mode' );
if ( ! class_exists( 'MAD4B_SCP_MCP_MU_Bootstrap_Refresh' ) ) $fail( 'MU refresh service unavailable' );
$status = MAD4B_SCP_MCP_MU_Bootstrap_Refresh::status();
if ( empty( $status['eligible'] ) ) $fail( 'refresh must be eligible on exact Staging origin' );
if ( empty( $status['present'] ) ) $fail( 'stale managed MU file was not observed' );
if ( empty( $status['managed'] ) ) $fail( 'stale MU file must be recognized as MAD4B-managed' );
if ( empty( $status['refresh_applied'] ) ) $fail( 'stale managed MU file was not refreshed' );
if ( empty( $status['next_request_required'] ) ) $fail( 'refresh must require the next PHP request' );
if ( 'managed_mu_refreshed_for_next_request' !== ( isset( $status['state'] ) ? $status['state'] : '' ) ) $fail( 'unexpected refresh state' );
if ( ! empty( $status['blocker'] ) ) $fail( 'refresh reported blocker: ' . $status['blocker'] );

$source = $wp_path . '/wp-content/plugins/mad4b-site-control-plane/bootstrap/mad4b-mcp-adapter-mu-bootstrap.php';
$destination = $wp_path . '/wp-content/mu-plugins/000-mad4b-mcp-adapter-bootstrap.php';
if ( ! is_readable( $source ) || ! is_readable( $destination ) ) $fail( 'source/destination unavailable after refresh' );
$source_hash = hash_file( 'sha256', $source );
$destination_hash = hash_file( 'sha256', $destination );
if ( ! is_string( $source_hash ) || ! is_string( $destination_hash ) || ! hash_equals( $source_hash, $destination_hash ) ) $fail( 'refreshed MU bytes do not match current source' );

if ( ! class_exists( 'MAD4B_SCP_Audit' ) ) $fail( 'audit service unavailable' );
$audit = MAD4B_SCP_Audit::storage_status();
if ( empty( $audit['ready'] ) ) $fail( 'audit storage not ready after refresh' );

echo 'mad4b.site-control-plane.mcp-mu-refresh-first-request.v1: PASS' . PHP_EOL;
