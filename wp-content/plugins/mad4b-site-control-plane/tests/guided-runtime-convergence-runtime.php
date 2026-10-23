<?php
/** Pure PHP7.4+ tests for the bounded guided Runtime Convergence projection. */
define( 'ABSPATH', '/' );
function add_action( $name, $callback, $priority = 10 ) { return true; }
function sanitize_text_field( $value ) { return (string) $value; }
require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-runtime-convergence.php';
function assert_guided( $condition, $reason ) { if ( ! $condition ) { fwrite( STDERR, "FAIL: " . $reason . PHP_EOL ); exit(1); } }
$old = array( 'state' => 'pending_manual_resume', 'target_identity' => array( 'source_commit_sha' => str_repeat( 'a', 40 ) ) );
$new = array( 'source_commit_sha' => str_repeat( 'b', 40 ) );
$v = MAD4B_SCP_Runtime_Convergence::checkpoint_operator_state( $old, $new, array() );
assert_guided( $v['state'] === 'superseded_audit_only', 'old checkpoint must not be active gate when current build ready' );
assert_guided( ! $v['replay_old_checkpoint_allowed'] && ! $v['checkpoint_modified'], 'history is immutable' );
$v = MAD4B_SCP_Runtime_Convergence::checkpoint_operator_state( $old, $new, array( 'authority_binding:owner_gate' ) );
assert_guided( $v['state'] === 'historical_checkpoint_independent_blockers', 'current blockers cannot be hidden' );
$v = MAD4B_SCP_Runtime_Convergence::checkpoint_operator_state( $old, array( 'source_commit_sha' => str_repeat( 'a', 40 ) ), array() );
assert_guided( $v['state'] === 'current_candidate_core_ready', 'matching candidate ready' );
$v = MAD4B_SCP_Runtime_Convergence::checkpoint_operator_state( $old, array(), array() );
assert_guided( $v['state'] === 'checkpoint_identity_unverified', 'unknown identity is not trusted' );
$phases = array(
  'deployment' => array( 'state' => 'ready', 'required' => true, 'auto_safe' => false ),
  'schema' => array( 'state' => 'pending', 'required' => true, 'auto_safe' => true ),
  'authority_binding' => array( 'state' => 'owner_gate', 'required' => true, 'auto_safe' => false ),
  'provider_closure' => array( 'state' => 'conditional_gate', 'required' => false, 'auto_safe' => false ),
);
$v = MAD4B_SCP_Runtime_Convergence::guided_next_actions( $phases, array_keys( $phases ) );
assert_guided( $v['next_phase'] === 'schema', 'schema first eligible safe step' );
assert_guided( $v['steps'][2]['processing_lane'] === 'exact_owner_gate', 'owner gate retained' );
assert_guided( ! $v['mutation_performed'] && ! $v['owner_approval_bypassed'] && ! $v['production_authorized'], 'never authorizes' );
$policy = MAD4B_SCP_Runtime_Convergence::diagnostic_retry_policy();
assert_guided( $policy['fallback_read'] === 'mad4b/read-snapshot-header', 'bounded fallback available' );
assert_guided( ! $policy['retry_uncertain_mutation_allowed'] && ! $policy['parallel_deep_probe_allowed'], 'no dangerous retries' );
// Historical MCP diagnostic lane: exact old checkpoint, current verified
// build, owner-only gate, never production, write or general-grace bypass.
$old_identity = array(
	'version' => '0.4.0-rc.102',
	'source_commit_sha' => str_repeat( 'a', 40 ),
	'build_fingerprint' => str_repeat( 'b', 64 ),
	'package_manifest_digest' => str_repeat( 'c', 64 ),
	'artifact_identity' => 'old-rc102'
);
$current_identity = array(
	'version' => '0.4.0-rc.103',
	'source_commit_sha' => str_repeat( 'd', 40 ),
	'build_fingerprint' => str_repeat( 'e', 64 ),
	'package_manifest_digest' => str_repeat( 'f', 64 ),
	'artifact_identity' => 'current-rc103'
);
$checkpoint = array(
	'source' => 'self_update', 'state' => 'pending_manual_resume',
	'resume_blocker' => 'mad4b_automation_automation_kill_switch',
	'target_identity' => $old_identity
);
$phases = array(
	'deployment' => array( 'required' => true, 'state' => 'pending',
		'details' => array( 'cached_release_target_present' => true,
			'manifest_state' => 'ready', 'target_identity' => $old_identity ) ),
	'schema' => array( 'required' => true, 'state' => 'ready' ),
	'local_oauth_store' => array( 'required' => true, 'state' => 'ready' ),
	'managed_skills' => array( 'required' => true, 'state' => 'ready' ),
	'authority_binding' => array( 'required' => true, 'state' => 'owner_gate' ),
	'external_acceptance' => array( 'required' => false, 'state' => 'pending' )
);
$blockers = array( 'deployment:pending', 'authority_binding:owner_gate' );
$graph = array(
	'environment' => 'staging', 'phase_graph_valid' => true,
	'state' => 'convergence_required', 'current_identity' => $current_identity,
	'checkpoint' => $checkpoint, 'phases' => $phases,
	'required_blockers' => $blockers,
	'checkpoint_operator_state' => MAD4B_SCP_Runtime_Convergence::checkpoint_operator_state( $checkpoint, $current_identity, $blockers )
);
// Test the exact private classifier used by the Staging-certified runtime.
// Reflection is test-only and does not expose or alter an MCP Ability.
$historical_classifier = new ReflectionMethod( 'MAD4B_SCP_Runtime_Convergence', 'superseded_checkpoint_mcp_read_safe' );
$historical_classifier->setAccessible( true );
$can = static function ( $cp, $current, $status, $not_before = 0 ) use ( $historical_classifier ) {
	return $historical_classifier->invoke( null, $cp, $current, $not_before, $status );
};
assert_guided( $can( $checkpoint, $current_identity, $graph ), 'staging superseded signed release + owner gate allows diagnostics' );
$changed = $graph;
$changed['environment'] = 'production';
assert_guided( ! $can( $checkpoint, $current_identity, $changed ), 'production never permitted' );
$changed = $graph;
$changed['phases']['schema']['state'] = 'pending';
assert_guided( ! $can( $checkpoint, $current_identity, $changed ), 'schema blocker is not a read exception' );
$changed = $graph;
$changed['phases']['deployment']['details']['manifest_state'] = 'invalid';
assert_guided( ! $can( $checkpoint, $current_identity, $changed ), 'unverified cached release blocked' );
$changed = $graph;
$changed['required_blockers'][] = 'oauth:pending';
assert_guided( ! $can( $checkpoint, $current_identity, $changed ), 'additional blocker denied' );
$changed = $graph;
$changed['phases']['deployment']['details']['target_identity']['build_fingerprint'] = str_repeat('0',64);
assert_guided( ! $can( $checkpoint, $current_identity, $changed ), 'cached release fingerprint mismatch denied' );
$changed = $graph;
$changed['phases']['deployment']['details']['target_identity']['version'] = '0.4.0-rc.104';
assert_guided( ! $can( $checkpoint, $current_identity, $changed ), 'newer cached release denied' );
$changed = $graph;
$changed['current_identity']['source_commit_sha'] = str_repeat('1',40);
assert_guided( ! $can( $checkpoint, $current_identity, $changed ), 'installed source identity mismatch denied' );
$changed = $checkpoint;
$changed['resume_blocker'] = 'other_operator_blocker';
assert_guided( ! $can( $changed, $current_identity, $graph ), 'unrelated checkpoint blocked' );
assert_guided( ! $can( $checkpoint, $current_identity, $graph, time()+30 ), 'minimum quiet period preserved' );
$changed = $graph;
$changed['required_blockers'] = array( 'authority_binding:owner_gate' );
$changed['phases']['deployment']['state'] = 'ready';
$changed['checkpoint_operator_state'] = MAD4B_SCP_Runtime_Convergence::checkpoint_operator_state( $checkpoint, $current_identity, $changed['required_blockers'] );
assert_guided( $can( $checkpoint, $current_identity, $changed ), 'owner-only gate read remains available' );

fwrite( STDOUT, "PASS guided-convergence v1: checkpoint, owner, bounded retry
" );
