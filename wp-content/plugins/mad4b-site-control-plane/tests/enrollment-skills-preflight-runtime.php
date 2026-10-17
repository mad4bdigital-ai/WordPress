<?php
/* Native PHP 7.4+ read-only decision test. Does not load WordPress runtime. */
define( 'ABSPATH', __DIR__ . '/' );
require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-enrollment-dispatch.php';
function mad4b_preflight_assert( $condition, $message ) {
	if ( ! $condition ) { fwrite( STDERR, 'FAIL: ' . $message . "\n" ); exit( 1 ); }
}
function mad4b_preflight_signals() {
	return array(
		'staging_profile_ready' => true,
		'skills_enabled' => true,
		'editor_enabled' => true,
		'registry_available' => true,
		'remote_services_available' => true,
		'no_active_lock' => true,
		'current_build_identity_ready' => true,
	);
}
$good = MAD4B_SCP_Enrollment_Dispatch::managed_skills_preflight_from_signals( mad4b_preflight_signals() );
mad4b_preflight_assert( $good['structurally_ready'] && 'structurally_ready' === $good['state'], 'All independently observed prerequisites must be present.' );
mad4b_preflight_assert( ! $good['authorization_performed'] && ! $good['permission_evaluated'] && ! $good['execution_performed'] && ! $good['mutation_performed'], 'Never imply authorization or execution.' );
$cases = array(
	'editor_enabled' => 'managed_skills_editor_disabled',
	'registry_available' => 'skill_registry_unavailable',
	'staging_profile_ready' => 'site_profile_not_authoritative_staging',
	'skills_enabled' => 'skills_disabled_for_site',
	'remote_services_available' => 'skill_services_unavailable',
	'no_active_lock' => 'managed_skills_operation_in_progress',
	'current_build_identity_ready' => 'exact_build_identity_unverified',
);
foreach ( $cases as $signal => $reason ) {
	$signals = mad4b_preflight_signals();
	$signals[ $signal ] = false;
	$blocked = MAD4B_SCP_Enrollment_Dispatch::managed_skills_preflight_from_signals( $signals );
	mad4b_preflight_assert( 'blocked' === $blocked['state'], $signal . ' failure must block.' );
	mad4b_preflight_assert( in_array( $reason, $blocked['blockers'], true ), $reason . ' must be actionable.' );
}
$unknown = MAD4B_SCP_Enrollment_Dispatch::managed_skills_preflight_from_signals( array() );
mad4b_preflight_assert( ! $unknown['structurally_ready'] && count( $unknown['blockers'] ) === 7, 'Unknown inputs fail closed.' );
mad4b_preflight_assert( $good['per_request_oauth_step_up_unverified'] && ! $good['production_mutation_allowed'], 'Live OAuth and Production are never certified by catalog discovery.' );
fwrite( STDOUT, "PASS: managed Skills enrollment preflight pure model (11 assertions/scenarios)\n" );
