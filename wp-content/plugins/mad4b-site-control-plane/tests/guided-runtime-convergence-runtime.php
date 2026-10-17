<?php
/** Pure PHP7.4+ tests for the bounded guided Runtime Convergence projection. */
define( 'ABSPATH', '/' );
function add_action( $name, $callback, $priority = 10 ) { return true; }
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
fwrite( STDOUT, "PASS guided-convergence v1: checkpoint, owner, bounded retry
" );
