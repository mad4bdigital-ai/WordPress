<?php
/** Disposable no-WordPress fixture. Pure reducers only: no host or DB IO. */
define( 'ABSPATH', __DIR__ );
$registered = array();
function add_action( $name, $callback, $priority = 10 ) {}
function wp_has_ability( $name ) { global $registered; return isset( $registered[ $name ] ); }
function wp_register_ability( $name, $definition ) { global $registered; $registered[ $name ] = $definition; }
final class MAD4B_SCP_Policy { public static function can_read() { return true; } }
final class MAD4B_SCP_Staging_Certification {
	public static $plan;
	public static $native;
	public static function convergence_plan( $input ) { return self::$plan; }
	public static function status( $input ) { return self::$native; }
}
require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-operational-remediation.php';
$tests = 0;
$ok = static function ( $passed, $message ) use ( &$tests ) {
	++$tests;
	if ( ! $passed ) { fwrite( STDERR, 'FAIL ' . $tests . ': ' . $message . PHP_EOL ); exit( 1 ); }
};
MAD4B_SCP_Operational_Remediation::register_abilities();
$ok( isset( $registered['mad4b/operational-remediation-status'] ), 'status ability exists' );
$ok( isset( $registered['mad4b/operational-remediation-prepare'] ), 'preparation ability exists' );
foreach ( $registered as $definition ) {
	$ok( 'mad4b-read' === $definition['category'], 'only Read lane' );
	$ok( true === $definition['meta']['annotations']['readonly'], 'nonmutating annotation' );
	$ok( false === $definition['meta']['mcp']['public'], 'not public MCP operation' );
}
$sha = str_repeat( 'a', 64 );
$source = str_repeat( 'b', 40 );
$binding = array(
	'source_commit_sha' => $source,
	'site_uuid' => '123e4567-e89b-42d3-a456-426614174000',
	'site_profile_digest' => str_repeat( 'c', 64 ),
	'site_origin' => 'https://staging.example.test',
	'environment' => 'staging',
	'nonproduction_site_ready' => true,
);
$plan = array(
	'plan_binding' => $binding,
	'plan_sha256' => $sha,
	'current_ready' => false,
	'blocking_gates' => array( 'browser_runtime', 'deployment_host_binding' ),
	'gate_coverage_complete' => true,
	'gate_action_coverage' => array(
		'browser_runtime' => array( 'browser_acceptance' ),
		'deployment_host_binding' => array( 'deployment_identity_review' ),
	),
	'actions' => array(
		array( 'action_id' => 'browser_acceptance', 'kind' => 'external_executor_job',
			'executor' => 'external_browser_agent', 'automatic_execution_allowed' => false,
			'apply_ability' => 'mad4b/browser-acceptance-run',
			'apply_ability_registered' => false,
			'readback_ability' => 'mad4b/browser-acceptance-result',
			'readback_ability_registered' => false,
			'target_gates' => array( 'browser_runtime' ) ),
		array( 'action_id' => 'deployment_identity_review', 'kind' => 'host_bootstrap_review',
			'executor' => 'authorized_host_operator',
			'automatic_execution_allowed' => false,
			'target_gates' => array( 'deployment_host_binding' ) ),
	),
	'plan_integrity_blockers' => array(),
	'dispatch_allowed' => false,
	'autonomous_mutation_authorized' => false,
	'readiness_domains' => array( 'release_acceptance' => array( 'ready' => false ) ),
	'live_acceptance_overlay' => array( 'included' => false, 'ready' => null ),
);
$native = array( 'ready' => false, 'gates' => array(
	'browser_runtime' => array( 'ready' => false, 'remediation_owner' => 'external_browser' ),
	'exact_build' => array( 'ready' => true ),
) );
MAD4B_SCP_Staging_Certification::$plan = $plan;
MAD4B_SCP_Staging_Certification::$native = $native;
$result = MAD4B_SCP_Operational_Remediation::status( array() );
$ok( $result['state'] === 'REMEDIATION_REQUIRED', 'blocked plan produces work items' );
$ok( $result['diagnostic_integrity_ready'] === true, 'exact evidence can be inspected' );
$ok( count( $result['work_items'] ) === 2, 'native and dynamically added gates covered' );
$ok( $result['ready_for_automatic_repair'] === false, 'no autonomous repair' );
$ok( $result['full_release_certified'] === false, 'cannot mint release' );
$ok( $result['read_only'] === true && $result['mutation_performed'] === false, 'no mutation' );
$ok( $result['work_items'][0]['paths'][0]['apply_registered'] === false, 'uninstalled executor not fabricated' );
$ok( $result['work_items'][1]['gate_id'] === 'deployment_host_binding', 'site deployment is independent gate' );
$prepare = MAD4B_SCP_Operational_Remediation::prepare( array(
	'action_id' => 'browser_acceptance',
	'expected_plan_sha256' => $sha,
	'expected_source_commit_sha' => $source,
) );
$ok( $prepare['state'] === 'PROVIDER_DISCOVERY_REQUIRED', 'missing provider separately diagnosed' );
$ok( $prepare['ready_for_dispatch'] === false && $prepare['approval_issued'] === false, 'no execution ticket' );
$ok( $prepare['site_uuid'] === $binding['site_uuid'], 'site identity remains bound' );
$stale = MAD4B_SCP_Operational_Remediation::prepare( array(
	'action_id' => 'browser_acceptance',
	'expected_plan_sha256' => str_repeat( 'd', 64 ),
	'expected_source_commit_sha' => $source,
) );
$ok( $stale['state'] === 'DENIED' && in_array( 'REPLAN_REQUIRED', $stale['blockers'], true ),
	'stale plan rejected' );
$valid = $plan;
$valid['actions'][0]['apply_ability_registered'] = true;
$handoff = MAD4B_SCP_Operational_Remediation::prepare_from_plan( $valid,
	'browser_acceptance', $sha, $source );
$ok( $handoff['state'] === 'SEPARATE_GOVERNED_APPROVAL_REQUIRED' && $handoff['approval_issued'] === false,
	'registered executor remains separately governed' );
$auto = $plan;
$auto['actions'][0]['automatic_execution_allowed'] = true;
$auto_result = MAD4B_SCP_Operational_Remediation::reduce( $native, $auto );
$ok( $auto_result['diagnostic_integrity_ready'] === false, 'auto-execution metadata invalidates inventory' );
$ok( MAD4B_SCP_Operational_Remediation::prepare_from_plan( $auto,
	'browser_acceptance', $sha, $source )['state'] === 'DENIED', 'auto-executing action refused' );
$conflict = $native;
$conflict['gates']['browser_runtime']['ready'] = true;
$drift = MAD4B_SCP_Operational_Remediation::reduce( $conflict, $plan );
$ok( in_array( 'native_convergence_readiness_conflict:browser_runtime',
	$drift['plan_integrity_blockers'], true ), 'cross-projection divergence blocked' );
$prod = $plan;
$prod['plan_binding']['environment'] = 'production';
$ok( MAD4B_SCP_Operational_Remediation::reduce( $native, $prod )['diagnostic_integrity_ready'] === false,
	'production cannot be reported Staging ready' );
$ok( MAD4B_SCP_Operational_Remediation::prepare_from_plan( $prod,
	'browser_acceptance', $sha, $source )['state'] === 'DENIED', 'production handoff denied' );
$not_covered = $plan;
$not_covered['gate_action_coverage']['browser_runtime'] = array( 'unknown_action' );
$coverage = MAD4B_SCP_Operational_Remediation::reduce( $native, $not_covered );
$ok( $coverage['diagnostic_integrity_ready'] === false, 'missing action does not disappear' );
$unbound_action = $plan;
$unbound_action['actions'][0]['target_gates'] = array();
$ok( MAD4B_SCP_Operational_Remediation::prepare_from_plan( $unbound_action,
	'browser_acceptance', $sha, $source )['state'] === 'DENIED',
	'actions not bound to blocked native/Live gate never become work tickets' );
$missing_canonical = MAD4B_SCP_Operational_Remediation::reduce( array(), $plan );
$ok( in_array( 'canonical_gate_registry_unavailable',
	$missing_canonical['plan_integrity_blockers'], true ),
	'missing native gate inventory blocks readiness rather than assuming green' );
$missing_origin = $plan;
unset( $missing_origin['plan_binding']['site_origin'] );
$ok( MAD4B_SCP_Operational_Remediation::reduce( $native, $missing_origin )['diagnostic_integrity_ready'] === false,
	'missing enrolled origin blocks cross-tenant projection' );
$bad_integrity = $plan;
$bad_integrity['plan_integrity_blockers'] = array( 'unverified_snapshot' );
$ok( MAD4B_SCP_Operational_Remediation::prepare_from_plan( $bad_integrity,
	'browser_acceptance', $sha, $source )['state'] === 'DENIED', 'invalid plan refused' );
$live = MAD4B_SCP_Operational_Remediation::reduce( $native, $plan, true );
$ok( $live['live_acceptance_ready'] === false, 'absent external evidence never passes' );
$empty = $plan;
$empty['actions'] = array();
$empty['blocking_gates'] = array();
$empty['gate_action_coverage'] = array();
$empty['current_ready'] = true;
$ok( MAD4B_SCP_Operational_Remediation::reduce(
	array( 'ready' => true, 'gates' => array( 'exact_build' => array( 'ready' => true ) ) ), $empty
)['full_release_certified'] === false, 'even green Staging cannot self-certify release' );
$native_aggregate_blocked = array( 'ready' => false, 'gates' => array(
	'exact_build' => array( 'ready' => true ),
) );
$native_not_ready_result = MAD4B_SCP_Operational_Remediation::reduce( $native_aggregate_blocked, $empty );
$ok( $native_not_ready_result['diagnostic_integrity_ready'] === true,
	'aggregate native readiness test uses an otherwise valid empty plan' );
$ok( $native_not_ready_result['native_staging_ready'] === false
	&& $native_not_ready_result['staging_release_gates_ready'] === false,
	'green convergence plan cannot override blocked native staging readiness' );
echo 'OPERATIONAL_REMEDIATION_RUNTIME: PASS ' . $tests . PHP_EOL;
