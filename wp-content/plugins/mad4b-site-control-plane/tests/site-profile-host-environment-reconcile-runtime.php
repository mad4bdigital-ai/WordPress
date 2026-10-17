<?php
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );
function add_action( $name, $callback, $priority = 10 ) { return true; }
require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-host-bridge.php';
$ready = array(
	'configured_environment' => 'staging', 'environment_sync_mode' => 'host_managed',
	'environment_sync_state' => 'awaiting_host_bootstrap', 'wordpress_environment' => 'production',
	'wordpress_environment_explicit' => false, 'authority_ready' => true,
	'origin_match' => true, 'environment_match' => true,
	'profile_environment_authoritative' => true, 'deployment_binding_configured' => true,
	'deployment_binding_bound' => true, 'deployment_binding_match' => true,
	'same_origin_clone_protection' => true,
);
$cases = array(
	array( array(), 'ready_for_host_plan' ),
	array( array( 'configured_environment' => 'production' ), 'blocked_non_staging_profile' ),
	array( array( 'environment_sync_mode' => 'profile_only' ), 'blocked_host_managed_mode_disabled' ),
	array( array( 'environment_sync_state' => 'host_aligned', 'wordpress_environment' => 'staging',
		'wordpress_environment_explicit' => true ), 'already_aligned' ),
	array( array( 'wordpress_environment_explicit' => true ), 'blocked_explicit_host_conflict' ),
	array( array( 'authority_ready' => false ), 'blocked_site_identity' ),
	array( array( 'origin_match' => false ), 'blocked_site_identity' ),
	array( array( 'profile_environment_authoritative' => false ), 'blocked_site_identity' ),
	array( array( 'deployment_binding_match' => false ), 'blocked_host_binding' ),
	array( array( 'same_origin_clone_protection' => false ), 'blocked_host_binding' ),
	array( array( 'wordpress_environment' => 'local' ), 'blocked_host_environment_conflict' ),
);
foreach ( $cases as $i => $case ) {
	$got = MAD4B_SCP_Host_Bridge::profile_environment_reconcile_readiness( array_merge( $ready, $case[0] ) );
	if ( $got !== $case[1] ) { fwrite( STDERR, "FAIL: case $i expected {$case[1]} got $got\n" ); exit( 1 ); }
}
echo 'PASS: host environment reconciliation decision matrix (11/11)' . PHP_EOL;
