<?php
/** No WordPress, database, network, or authority: exercise the pure evaluator only. */
define( 'ABSPATH', __DIR__ );
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-staging-write-authority-convergence.php';
function verify_case( $label, $result ) {
	if ( ! $result ) { fwrite( STDERR, 'FAIL: ' . $label . PHP_EOL ); exit( 1 ); }
	echo 'PASS: ' . $label . PHP_EOL;
}
$expected = array(
	'site_uuid'=>'site', 'site_profile_revision'=>2, 'site_profile_digest'=>'profile',
	'source_commit_sha'=>'head', 'build_fingerprint'=>'build',
	'package_manifest_digest'=>'manifest', 'artifact_identity'=>'artifact',
	'write_snapshot'=>array('write_tool_count'=>2,'write_inventory_fingerprint'=>'inventory','grant_rows_fingerprint'=>'grants'),
);
$observed = array(
	'site'=>array('environment'=>'staging','site_uuid'=>'site','site_profile_revision'=>2,'site_profile_digest'=>'profile',
		'write_enabled'=>true,'raw_sql_breakglass_enabled'=>false),
	'provenance'=>array('source_commit_sha'=>'head','build_fingerprint'=>'build',
		'package_manifest_digest'=>'manifest','artifact_identity'=>'artifact'),
	'binding'=>array('required'=>true,'match'=>true,'identity_completeness'=>'complete',
		'stored_source_commit_sha'=>'head','stored_build_fingerprint'=>'build',
		'stored_package_manifest_digest'=>'manifest','stored_artifact_identity'=>'artifact'),
	'current'=>array('ready'=>true,'cheap_effective'=>true,'current_grant_snapshot_ready'=>true,
		'candidate_binding_match'=>true,'candidate_bootstrap_exception'=>false,'blockers'=>array()),
	'write'=>array('current_ready'=>true,'effective_ready'=>true,'write_tool_count'=>2,'exact_grants_existing'=>2,
		'write_inventory_fingerprint'=>'inventory','grant_rows_fingerprint'=>'grants'),
);
foreach (array('exact_grants_missing_count','stale_allow_grants_count','unreviewed_stale_allow_grants_count',
	'broad_environment_grants_count','duplicate_exact_allow_grants_count',
	'current_agent_wildcard_grants','global_registry_wildcard_grants') as $counter) $observed['write'][$counter] = 0;
$eval = static function($r) use ($expected) {
	return MAD4B_SCP_Staging_Write_Authority_Convergence::evaluate_postconditions($expected,$r);
};
$ok = $eval($observed);
verify_case('full readback passes without authorizing', $ok['verified'] && ! $ok['authorizing']
	&& 'caller_supplied_not_trusted' === $ok['evidence_origin']);
foreach (array(
	'stale candidate'=>array('binding','stored_source_commit_sha','old'),
	'stale profile'=>array('site','site_profile_revision',3),
	'production'=>array('site','environment','production'),
	'raw sql'=>array('site','raw_sql_breakglass_enabled',true),
	'stale artifact'=>array('provenance','artifact_identity','old'),
	'stale grant fingerprint'=>array('write','grant_rows_fingerprint','old'),
	'wildcard'=>array('write','global_registry_wildcard_grants',1),
	'readiness blocked'=>array('current','ready',false),
	'bootstrap exception'=>array('current','candidate_bootstrap_exception',true),
) as $label=>$change) {
	$bad=$observed; $bad[$change[0]][$change[1]]=$change[2];
	verify_case($label.' rejected', ! $eval($bad)['verified']);
}
$absent=$observed; unset($absent['binding']);
verify_case('missing readback rejected', ! $eval($absent)['verified']);
echo 'STAGING_WRITE_SELF_CONFIRMATION: PASS (hermetic predicate only)' . PHP_EOL;
