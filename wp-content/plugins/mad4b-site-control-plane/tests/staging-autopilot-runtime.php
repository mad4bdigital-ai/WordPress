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

/**
 * Check every gated stage in the automatic read-only handoff.
 * A green environment is not the same as a clone-safe host binding.
 */
$aligned = array_merge( $ready, array(
	'wordpress_environment' => 'staging',
	'wordpress_environment_explicit' => true,
	'wordpress_profile_mismatch' => false,
	'deployment_binding_configured' => true,
	'deployment_binding_bound' => true,
	'deployment_binding_match' => true,
	'same_origin_clone_protection' => true,
	'authority_ready' => true,
	'site_uuid' => '00000000-0000-4000-8000-000000000001',
	'revision' => 3,
	'profile_digest' => str_repeat( 'a', 64 ),
) );
$write_ok = array( 'ready' => true, 'current_readiness_blockers' => array() );
$skills_ok = array( 'ready' => true );
$dev_ok = array( 'execution' => array( 'execution_ready' => true, 'blockers' => array() ) );
$scenarios = array(
	array( $aligned, $write_ok, $skills_ok, $dev_ok, 'awaiting_exact_head_native_acceptance', 'none' ),
	array( array_merge( $aligned, array( 'deployment_binding_configured' => false, 'deployment_binding_bound' => false, 'same_origin_clone_protection' => false ) ), $write_ok, $skills_ok, $dev_ok, 'blocked_host_deployment_binding', 'provision_unique_host_deployment_binding' ),
	array( array_merge( $aligned, array( 'deployment_binding_bound' => false, 'same_origin_clone_protection' => false ) ), $write_ok, $skills_ok, $dev_ok, 'blocked_host_deployment_binding', 'save_exact_site_profile_to_bind_host_secret' ),
	array( array_merge( $aligned, array( 'deployment_binding_match' => false, 'same_origin_clone_protection' => false ) ), $write_ok, $skills_ok, $dev_ok, 'blocked_host_deployment_binding', 'stop_and_review_deployment_binding_drift' ),
	array( $aligned, array( 'ready' => false, 'current_readiness_blockers' => array( 'candidate_binding_not_current' ) ), $skills_ok, $dev_ok, 'blocked_write_authority_not_current', 'review_exact_write_only_convergence_handshake' ),
	array( $aligned, array(), $skills_ok, $dev_ok, 'write_authority_not_evaluated', 'review_exact_write_only_convergence_handshake' ),
	array( $aligned, $write_ok, array( 'ready' => false ), $dev_ok, 'blocked_managed_skills_not_current', 'review_exact_managed_skills_reconciliation' ),
	array( $aligned, $write_ok, array(), $dev_ok, 'managed_skills_not_evaluated', 'review_exact_managed_skills_reconciliation' ),
	array( $aligned, $write_ok, $skills_ok, array( 'execution' => array( 'execution_ready' => false, 'blockers' => array( 'resource_limiter_unavailable', 'network_isolation_unavailable' ) ) ), 'blocked_developer_host_prerequisites', 'inspect_staging_host_sandbox_and_process_limits' ),
	array( $aligned, $write_ok, $skills_ok, array(), 'developer_execution_not_evaluated', 'inspect_staging_host_sandbox_and_process_limits' ),
	array( array_merge( $aligned, array( 'wordpress_environment' => 'production', 'wordpress_environment_explicit' => true ) ), $write_ok, $skills_ok, $dev_ok, 'blocked_site_environment_or_identity', 'host_operator_reconcile_explicit_environment' ),
	array( array_merge( $aligned, array( 'origin_match' => false ) ), $write_ok, $skills_ok, $dev_ok, 'blocked_site_environment_or_identity', 'review_exact_site_profile' ),
);
foreach ( $scenarios as $index => $case ) {
	$result = MAD4B_SCP_Staging_Autopilot::automation_plan( $case[0], $case[1], $case[2], $case[3] );
	if ( $result['state'] !== $case[4] || $result['next_action_id'] !== $case[5] ) {
		fwrite( STDERR, "FAIL automation gate $index: " . $result['state'] . " / " . $result['next_action_id'] . "\n" );
		exit( 1 );
	}
	if ( count( $result['assistant_workflow'] ) !== 5 || ! $result['read_only']
		|| $result['mutation_performed'] || $result['authorizing'] || $result['completion_certified']
		|| $result['execution_policy']['unattended_write_grant_allowed']
		|| $result['execution_policy']['unattended_host_install_allowed']
		|| $result['execution_policy']['production_mutation_allowed']
		|| $result['host_binding']['secret_read_or_generated'] ) {
		fwrite( STDERR, "FAIL automation safety $index\n" );
		exit( 1 );
	}
	if ( $index === 1 && ! $result['environment']['wordpress_explicit_staging_aligned'] ) {
		fwrite( STDERR, "FAIL independent WP environment vs host binding\n" );
		exit( 1 );
	}
	if ( $index === 4 && ( $result['assistant_workflow'][2]['ready']
		|| $result['assistant_workflow'][2]['blockers'] !== array( 'candidate_binding_not_current' ) ) ) {
		fwrite( STDERR, "FAIL stale exact grant projection\n" );
		exit( 1 );
	}
}
echo "PASS: 12 ordered Staging Autopilot gates; environment/binding/authority/skills/host separation\n";

/** Status read callback stubs: ensure live state projection does not infer PASS. */
class MAD4B_SCP_Site_Profile {
	public static function status() { return $GLOBALS['mad4b_test_site']; }
}
class MAD4B_SCP_Full_Staging_Authority {
	public static function status() { return $GLOBALS['mad4b_test_full']; }
}
class MAD4B_SCP_Skill_Runtime_Certification {
	public static function current_status() { return $GLOBALS['mad4b_test_skills']; }
}
$GLOBALS['mad4b_test_site'] = $aligned;
$GLOBALS['mad4b_test_full'] = array(
	'write' => array( 'ready' => false, 'current_readiness_blockers' => array( 'profile_revision_changed' ) ),
	'developer' => $dev_ok,
);
$GLOBALS['mad4b_test_skills'] = $skills_ok;
$live = MAD4B_SCP_Staging_Autopilot::status( array() );
if ( ! isset( $live['automation_plan']['state'] )
	|| 'blocked_write_authority_not_current' !== $live['automation_plan']['state']
	|| $live['automation_plan']['observations']['write_ready']
	|| $live['automation_plan']['assistant_workflow'][2]['blockers'] !== array( 'profile_revision_changed' )
	|| ! $live['automation_plan']['environment']['wordpress_explicit_staging_aligned'] ) {
	fwrite( STDERR, "FAIL status projection of post-save write authority drift\n" );
	exit( 1 );
}
$GLOBALS['mad4b_test_full']['write']['ready'] = true;
$GLOBALS['mad4b_test_full']['write']['current_readiness_blockers'] = array();
$GLOBALS['mad4b_test_skills'] = array( 'ready' => false );
$live = MAD4B_SCP_Staging_Autopilot::status( array() );
if ( 'blocked_managed_skills_not_current' !== $live['automation_plan']['state'] ) {
	fwrite( STDERR, "FAIL runtime managed Skill evidence projection\n" );
	exit( 1 );
}
echo "PASS: 2 live status composition refusals after Site Profile/Skill drift\n";
