<?php
/**
 * Disposable, no-WordPress runtime regression fixture for the pure convergence
 * reducer. No DB, network, host process, WordPress writes or release claims.
 */
define( 'ABSPATH', __DIR__ );
final class MAD4B_SCP_Site_Profile {
	public static $environment = 'staging';
	public static $origin = 'https://staging.example.test';
	public static $enrolled = true;
	public static function site_uuid() { return '123e4567-e89b-42d3-a456-426614174000'; }
	public static function profile_digest() { return str_repeat( 'f', 64 ); }
	public static function site_origin() { return self::$origin; }
	public static function current_environment() { return self::$environment; }
	public static function nonproduction_governed() { return self::$enrolled; }
	public static function site_urls_match_enrollment() { return self::$enrolled; }
}
require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-staging-certification.php';

$checks = 0;
$check = static function ( $passed, $label ) use ( &$checks ) {
	++$checks;
	if ( ! $passed ) {
		fwrite( STDERR, 'FAILED [' . $checks . ']: ' . $label . PHP_EOL );
		exit( 1 );
	}
};
$gates = array();
$names = array(
	'exact_build', 'safe_boot', 'context_authority',
	'brand_core_context_coverage', 'google_provider_connection',
	'managed_google_broker', 'skills_runtime', 'external_skill_snapshot',
	'write_authority', 'write_runtime', 'browser_runtime', 'performance_budget',
	'admin_query_performance', 'query_monitor_db_attribution',
	'oauth_live_authority_projection', 'rollback_candidate',
	'wp_import_export_exact_artifact', 'new_plugin_family_gate',
);
foreach ( $names as $name ) {
	$gates[ $name ] = array(
		'ready' => false,
		'source' => 'site_live_' . $name,
		'remediation_owner' => 'host_operator',
		'blockers' => array( 'certificate_not_observed' ),
	);
}
// The MCP handshake is already ready; an external snapshot still needs a
// *fresh* handshake action as a prerequisite, not a dangling dependency.
$gates['safe_boot']['ready'] = true;
$actions = array(
	array( 'action_id' => 'external_snapshot_refresh', 'kind' => 'external_evidence',
		'depends_on' => array( 'external_mcp_handshake_refresh' ),
		'automatic_execution_allowed' => false ),
	array( 'action_id' => 'browser_acceptance', 'kind' => 'external_executor_job',
		'automatic_execution_allowed' => true ),
	array( 'action_id' => 'frontend_performance_sampling', 'kind' => 'external_executor_job',
		'automatic_execution_allowed' => true ),
	array( 'action_id' => 'brand_core_convergence', 'kind' => 'hybrid_creation',
		'depends_on' => array(), 'automatic_execution_allowed' => false ),
	array( 'action_id' => 'write_authority_reconcile', 'kind' => 'governed_mutation',
		'automatic_execution_allowed' => false ),
	array( 'action_id' => 'provider_closure_review', 'kind' => 'read_only_followup',
		'automatic_execution_allowed' => true ),
);
$c = MAD4B_SCP_Staging_Certification::complete_convergence_coverage( $gates, $actions );
$check( $c['contract'] === 'mad4b.staging-gate-action-coverage.v1', 'contract identity' );
$check( $c['blocked_gate_count'] === count( $names ) - 1, 'ready gates excluded' );
$check( $c['covered_gate_count'] === count( $names ) - 1, 'all blocked gates covered' );
$check( $c['coverage_complete'] === true, 'coverage exact and non-authorizing' );
$check( $c['plan_integrity_blockers'] === array(), 'no dangling dependencies' );
$check( $c['authorizing'] === false && $c['mutation_performed'] === false, 'pure planning only' );
$check( ! isset( $c['gate_action_coverage']['safe_boot'] ), 'ready site handshake not falsely blocked' );
$check( count( $c['gate_action_coverage']['new_plugin_family_gate'] ) === 1,
	'future unknown gate has a safe review-only remediation' );
$by_id = array();
foreach ( $c['actions'] as $i => $action ) {
	$by_id[ $action['action_id'] ] = array( 'action' => $action, 'index' => $i );
	$check( $action['authorizing'] === false && $action['mutation_performed'] === false,
		'all actions non-authorizing regardless of source flags' );
}
$check( isset( $by_id['external_mcp_handshake_refresh'] ), 'fresh prerequisite generated' );
$check( $by_id['external_mcp_handshake_refresh']['index'] <
	$by_id['external_snapshot_refresh']['index'], 'external snapshot ordered after valid handshake' );
foreach ( array( 'browser_acceptance', 'frontend_performance_sampling' ) as $id ) {
	$row = $by_id[ $id ]['action'];
	$check( $row['automatic_execution_allowed'] === false, 'external executor cannot self-launch: ' . $id );
	$check( $row['external_preflight_required'] === true &&
		in_array( 'signed_replay_safe_receipt', $row['required_evidence'], true ),
		'external signed receipt demanded: ' . $id );
}
$unknown = $by_id['review_gate_new_plugin_family_gate']['action'];
$check( $unknown['automatic_execution_allowed'] === false &&
	$unknown['no_automatic_remediation_available'] === true,
	'unknown provider lane stays fail-closed' );
$check( $unknown['evidence_source'] === 'site_live_new_plugin_family_gate' &&
	$unknown['executor'] === 'host_operator', 'site-derived evidence and owner' );
$check( $by_id['provider_closure_review']['action']['read_only_plan'] === true,
	'existing native follow-up remains read-only' );
$reversed = MAD4B_SCP_Staging_Certification::complete_convergence_coverage(
	$gates, array_reverse( $actions )
);
$check( $reversed['actions'] === $c['actions'], 'deterministic ordering independent of input enumeration' );
$cycle = MAD4B_SCP_Staging_Certification::complete_convergence_coverage( array(), array(
	array( 'action_id' => 'alpha', 'depends_on' => array( 'beta' ), 'automatic_execution_allowed' => true ),
	array( 'action_id' => 'beta', 'depends_on' => array( 'alpha' ), 'automatic_execution_allowed' => true ),
) );
$check( $cycle['coverage_complete'] === false &&
	! empty( $cycle['plan_integrity_blockers'] ), 'cyclic remediation graph denied' );
foreach ( $cycle['actions'] as $item )
	$check( $item['automatic_execution_allowed'] === false, 'cyclic graph never executes' );
$missing = MAD4B_SCP_Staging_Certification::complete_convergence_coverage( array(), array(
	array( 'action_id' => 'alpha', 'depends_on' => array( 'missing_host_provider' ),
		'automatic_execution_allowed' => true ),
) );
$check( $missing['coverage_complete'] === false &&
	! empty( $missing['plan_integrity_blockers'] ), 'unresolvable provider prerequisite denied' );
$check( $missing['actions'][0]['automatic_execution_allowed'] === false,
	'missing host executor does not become automatic' );
$duplicate = MAD4B_SCP_Staging_Certification::complete_convergence_coverage( array(), array(
	array( 'action_id' => 'alpha', 'automatic_execution_allowed' => true ),
	array( 'action_id' => 'alpha', 'automatic_execution_allowed' => true ),
) );
$check( ! empty( $duplicate['plan_integrity_blockers'] ) &&
	$duplicate['actions'][0]['automatic_execution_allowed'] === false,
	'duplicate action identity denied' );
$empty = MAD4B_SCP_Staging_Certification::complete_convergence_coverage( array(), array() );
$check( $empty['coverage_complete'] === true &&
	$empty['blocked_gate_count'] === 0 &&
	$empty['actions'] === array(), 'empty ready site yields clean no-op plan' );

// Independent release acceptance is opt-in and must never be filled from
// Staging-only certificate booleans.
$release = MAD4B_SCP_Staging_Certification::merge_live_acceptance_gates(
	array( 'safe_boot' => array( 'ready' => true ) ),
	array( 'ready' => false, 'gates' => array(
		'environment_guard' => array( 'ready' => true, 'effective_ready' => true,
			'freshness_required' => true, 'fresh' => true ),
		'external_wpml' => array( 'ready' => false, 'effective_ready' => false,
			'state' => 'route_not_registered', 'source_contract' => 'external-wpml',
			'blockers' => array( 'route_not_registered' ) ),
		'browser_attestation' => array( 'ready' => true, 'effective_ready' => true,
			'freshness_required' => true, 'fresh' => false ),
	) )
);
$check( $release['included'] === true && $release['ready'] === false,
	'live acceptance cannot be self-certified' );
$check( $release['gate_count'] === 3, 'independent acceptance families preserved' );
$check( $release['gates']['live_acceptance_environment_guard']['ready'] === true,
	'fresh external success remains success' );
$check( $release['gates']['live_acceptance_external_wpml']['ready'] === false,
	'missing external WPML remains blocked' );
$check( $release['gates']['live_acceptance_browser_attestation']['ready'] === false,
	'stale signed browser evidence never becomes ready' );
$expanded = MAD4B_SCP_Staging_Certification::complete_convergence_coverage(
	$release['gates'], array()
);
$check( $expanded['coverage_complete'] === true &&
	isset( $expanded['gate_action_coverage']['live_acceptance_external_wpml'] ),
	'live acceptance blocker gets safe generic review' );
$check( $expanded['actions'][0]['authorizing'] === false,
	'live acceptance gate cannot mint authority' );
$empty_external = MAD4B_SCP_Staging_Certification::merge_live_acceptance_gates(
	array(), array( 'ready' => true, 'gates' => array() )
);
$check( $empty_external['ready'] === false &&
	isset( $empty_external['gates']['live_acceptance_evidence_unavailable'] ),
	'absent independent evidence cannot be accepted as ready' );
$invalid = MAD4B_SCP_Staging_Certification::complete_convergence_coverage(
	array( 'bad/name' => array( 'ready' => false ) ), array()
);
$check( $invalid['coverage_complete'] === false &&
	! empty( $invalid['plan_integrity_blockers'] ),
	'invalid gate identity cannot silently disappear' );
$mutating = MAD4B_SCP_Staging_Certification::complete_convergence_coverage(
	array(), array(
		array( 'action_id' => 'governed_write', 'kind' => 'governed_mutation',
			'automatic_execution_allowed' => true ),
		array( 'action_id' => 'external_reconnect', 'kind' => 'external_oauth_reauthorization',
			'automatic_execution_allowed' => true ),
	)
);
foreach ( $mutating['actions'] as $action ) {
	$check( $action['automatic_execution_allowed'] === false &&
		$action['independent_governed_preflight_required'] === true,
		'no effectful action may inherit automatic authority' );
}

// Fresh-read verification is the only plan completion surface. Technical
// Staging readiness remains distinct from independent release approval.
$head = str_repeat( 'a', 40 );
$hash = str_repeat( 'b', 64 );
$plan = array(
	'plan_sha256' => $hash,
	'plan_binding' => array(
		'source_commit_sha' => $head,
		'site_uuid' => '123e4567-e89b-42d3-a456-426614174000',
		'site_profile_digest' => str_repeat( 'f', 64 ),
		'site_origin' => 'https://staging.example.test',
		'environment' => 'staging',
		'nonproduction_site_ready' => true,
	),
	'current_ready' => true,
	'gate_coverage_complete' => true,
	'blocking_gates' => array(),
	'plan_integrity_blockers' => array(),
	'live_acceptance_overlay' => array( 'included' => false, 'ready' => null ),
);
$verified = MAD4B_SCP_Staging_Certification::compare_convergence_plan(
	$plan, $hash, $head, false
);
$check( $verified['state'] === 'CURRENT_STAGING_GATES_READY' && $verified['ready'] === true,
	'exact fresh Staging evidence can report Stage readiness' );
$check( $verified['full_release_certified'] === false &&
	$verified['authorizing'] === false && $verified['mutation_performed'] === false,
	'passing Staging check never authorizes release or mutation' );
$stale = MAD4B_SCP_Staging_Certification::compare_convergence_plan(
	$plan, str_repeat( 'c', 64 ), $head, false
);
$check( $stale['state'] === 'REPLAN_REQUIRED' && $stale['ready'] === false,
	'changed plan digest never replayed' );
$changed_head = MAD4B_SCP_Staging_Certification::compare_convergence_plan(
	$plan, $hash, str_repeat( 'd', 40 ), false
);
$check( $changed_head['state'] === 'REPLAN_REQUIRED' &&
	$changed_head['source_matches'] === false, 'changed source build never replayed' );
$blocked = $plan;
$blocked['blocking_gates'] = array( 'browser_runtime' );
$blocked['current_ready'] = false;
$pending = MAD4B_SCP_Staging_Certification::compare_convergence_plan(
	$blocked, $hash, $head, false
);
$check( $pending['state'] === 'NEEDS_EVIDENCE' && $pending['ready'] === false,
	'incomplete runtime keeps Staging pending' );
$missing_live = MAD4B_SCP_Staging_Certification::compare_convergence_plan(
	$plan, $hash, $head, true
);
$check( $missing_live['ready'] === false && $missing_live['state'] === 'NEEDS_EVIDENCE',
	'Staging-only status cannot satisfy independent Live Acceptance' );
$unbound = $plan;
$unbound['plan_binding']['source_commit_sha'] = '';
$bad_source = MAD4B_SCP_Staging_Certification::compare_convergence_plan(
	$unbound, $hash, $head, false
);
$check( $bad_source['state'] === 'CURRENT_BUILD_IDENTITY_UNAVAILABLE' &&
	$bad_source['ready'] === false, 'unknown package identity is never ready' );

// Cross-tenant / wrong environment / stale profile grants must never be
// inferred from the same Git commit and matching semantically valid plan.
$other_site = $plan;
$other_site['plan_binding']['site_uuid'] = '';
$wrong_site = MAD4B_SCP_Staging_Certification::compare_convergence_plan(
	$other_site, $hash, $head, false
);
$check( $wrong_site['state'] === 'GOVERNED_SITE_IDENTITY_UNAVAILABLE' &&
	$wrong_site['ready'] === false, 'missing tenant site UUID denied' );
$untrusted_profile = $plan;
$untrusted_profile['plan_binding']['site_profile_digest'] = 'unknown';
$wrong_profile = MAD4B_SCP_Staging_Certification::compare_convergence_plan(
	$untrusted_profile, $hash, $head, false
);
$check( $wrong_profile['state'] === 'GOVERNED_SITE_IDENTITY_UNAVAILABLE',
	'stale/invalid Site Profile revision cannot certify Staging' );
$prod = $plan;
$prod['plan_binding']['environment'] = 'production';
$wrong_env = MAD4B_SCP_Staging_Certification::compare_convergence_plan(
	$prod, $hash, $head, false
);
$check( $wrong_env['state'] === 'GOVERNED_SITE_IDENTITY_UNAVAILABLE' &&
	$wrong_env['ready'] === false, 'production profile cannot certify as Staging' );
$unset_proof = $plan;
$unset_proof['plan_binding']['nonproduction_site_ready'] = false;
$wrong_grant = MAD4B_SCP_Staging_Certification::compare_convergence_plan(
	$unset_proof, $hash, $head, false
);
$check( $wrong_grant['ready'] === false,
	'nonproduction eligibility must be verified independently' );
$generic = MAD4B_SCP_Staging_Certification::complete_convergence_coverage( array(), array(
	array( 'action_id' => 'provider_defined_read', 'kind' => 'custom_safe_hint',
		'automatic_execution_allowed' => true ),
) );
$check( $generic['actions'][0]['automatic_execution_allowed'] === false &&
	$generic['actions'][0]['external_execution_authority_granted'] === false,
	'unknown provider kind may not inherit automatic execution');
$collision = MAD4B_SCP_Staging_Certification::complete_convergence_coverage(
	array( 'future_gate' => array( 'ready' => false ) ),
	array( array( 'action_id' => 'review_gate_future_gate',
		'kind' => 'read_only_followup', 'automatic_execution_allowed' => true ) )
);
$check( $collision['coverage_complete'] === false &&
	in_array( 'generated_action_identity_collision:review_gate_future_gate',
		$collision['plan_integrity_blockers'], true ),
	'generated fallback cannot overwrite provider-owned action');
foreach ( $collision['actions'] as $item ) {
	$check( $item['automatic_execution_allowed'] === false,
		'conflicting remediation route is non-executable');
}

// The catalog can claim an operation without having a registered WordPress
// Ability; this can never become an executable or automatically repaired item.
$registered = MAD4B_SCP_Staging_Certification::observe_convergence_ability_registration( array(
	array( 'action_id' => 'not_installed_host',
		'kind' => 'external_executor_job',
		'apply_ability' => 'mad4b/supplemental-host-run',
		'readback_ability' => 'mad4b/supplemental-host-result',
		'automatic_execution_allowed' => true ),
) );
$check( $registered[0]['apply_ability_registered'] === false
	&& $registered[0]['readback_ability_registered'] === false,
	'missing host providers are discovered, not fabricated' );
$check( $registered[0]['execution_provider_missing'] === true
	&& $registered[0]['remediation_requires_adapter_discovery'] === true
	&& $registered[0]['automatic_execution_allowed'] === false,
	'catalog metadata never grants host execution' );
$check( $registered[0]['registration_is_not_execution_permission'] === true,
	'even observed registration would not grant runtime write authority' );

// Exact tenant-generic Site Profile read is an authority boundary, not a
// hostname heuristic. Simulate Staging, Production and revoked enrollment.
$site = MAD4B_SCP_Staging_Certification::convergence_site_identity();
$check( $site['ready'] === true && $site['environment'] === 'staging',
	'configured nonproduction identity is observable' );
MAD4B_SCP_Site_Profile::$environment = 'production';
$denied_production = MAD4B_SCP_Staging_Certification::convergence_site_identity();
$check( $denied_production['ready'] === false,
	'Staging planner refuses Production runtime masquerade' );
MAD4B_SCP_Site_Profile::$environment = 'staging';
MAD4B_SCP_Site_Profile::$enrolled = false;
$denied_enrollment = MAD4B_SCP_Staging_Certification::convergence_site_identity();
$check( $denied_enrollment['ready'] === false,
	'Revoked site enrollment cannot inherit old write authority' );
MAD4B_SCP_Site_Profile::$enrolled = true;
$check( MAD4B_SCP_Staging_Certification::convergence_site_identity()['ready'] === true,
	'Restored valid fixture status remains observable but grants nothing' );

// A manifest file change in the same PHP process must not be concealed by
// a cached provenance parser; the real plugin uses this for a late fence.
$manifest_dir = sys_get_temp_dir() . '/mad4b-aci01-' . getmypid() . '-' . mt_rand( 10000, 99999 ) . '/';
if ( ! mkdir( $manifest_dir, 0700 ) ) {
	fwrite( STDERR, 'Failed to create isolated manifest fixture dir' . PHP_EOL );
	exit( 1 );
}
define( 'MAD4B_SCP_DIR', $manifest_dir );
$manifest_path = $manifest_dir . 'MAD4B-BUILD-PROVENANCE.json';
file_put_contents( $manifest_path, '{"test":"version-a"}' );
$manifest_a = MAD4B_SCP_Staging_Certification::convergence_manifest_file_sha256();
file_put_contents( $manifest_path, '{"test":"version-b"}' );
$manifest_b = MAD4B_SCP_Staging_Certification::convergence_manifest_file_sha256();
$check( strlen( $manifest_a ) === 64 && strlen( $manifest_b ) === 64 &&
	! hash_equals( $manifest_a, $manifest_b ),
	'Manifest replacement within request changes uncached identity SHA' );
unlink( $manifest_path );
rmdir( $manifest_dir );
$check( MAD4B_SCP_Staging_Certification::convergence_manifest_file_sha256() === '',
	'Missing manifest never supplies a valid build fingerprint' );
echo 'STAGING_CONVERGENCE_COVERAGE_RUNTIME: PASS ' . $checks . PHP_EOL;
