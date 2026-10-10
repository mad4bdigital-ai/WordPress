<?php
// Pure policy tests, independent of WordPress, filesystem, credentials and host.
define( 'ABSPATH', __DIR__ . '/' );
require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-staging-autopilot.php';
$ready = array(
	'configured' => true, 'authority_ready' => true, 'origin_match' => true,
	'environment_match' => true, 'profile_environment_authoritative' => true,
	'configured_environment' => 'staging', 'wordpress_environment' => 'production',
	'wordpress_environment_explicit' => false,
	'implicit_nonproduction_override_confirmed' => true, 'environment_sync_mode' => 'profile_only',
	'deployment_binding_configured' => false,
);
$cases = array(
	array( $ready, 'admin_opt_in_required', true ),
	array( array_merge( $ready, array( 'environment_sync_mode' => 'host_managed' ) ), 'admin_reconcile_available', true ),
	array( array_merge( $ready, array( 'wordpress_environment' => 'staging' ) ), 'already_aligned', false ),
	array( array_merge( $ready, array( 'wordpress_environment_explicit' => true ) ), 'blocked_explicit_host_environment', false ),
	array( array_merge( $ready, array( 'authority_ready' => false ) ), 'blocked_profile_identity', false ),
	array( array_merge( $ready, array( 'configured_environment' => 'production' ) ), 'observe_only_not_staging', false ),
	array( array_merge( $ready, array( 'origin_match' => false ) ), 'blocked_profile_identity', false ),
	array( array_merge( $ready, array( 'implicit_nonproduction_override_confirmed' => false ) ), 'blocked_unattested_or_unrecognized_environment', false ),
);
foreach ( $cases as $index => $case ) {
	$output = MAD4B_SCP_Staging_Autopilot::decision( $case[0] );
	if ( $case[1] !== $output['state'] ||
		$case[2] !== $output['admin_autopilot_action_allowed'] ||
		! $output['read_only'] || $output['mutation_performed'] ||
		$output['production_mutation_allowed'] || $output['write_grants_auto_apply'] ||
		count( $output['assistant_workflow'] ) !== 5 ||
		$output['assistant_workflow'][2]['remote_write_allowed'] ||
		$output['assistant_workflow'][3]['host_prerequisites_auto_install'] ) {
		fwrite( STDERR, "FAIL case $index\n" );
		exit( 1 );
	}
}
echo "PASS: 8 Staging Autopilot policy cases, non-Production and no automatic grants\n";
