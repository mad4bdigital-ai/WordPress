<?php
/**
 * Offline regression for migrated stale restart checkpoint.
 * Pure decision checks: never loads WordPress or changes a stored option.
 */
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );
function add_action( $name, $callback, $priority = 10 ) { return true; }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-runtime-convergence.php';
function must( $condition, $reason ) {
	if ( ! $condition ) { fwrite( STDERR, 'FAIL: ' . $reason . PHP_EOL ); exit( 1 ); }
}
$method = new ReflectionMethod( 'MAD4B_SCP_Runtime_Convergence', 'superseded_checkpoint_graph_safe' );
$method->setAccessible( true );
$now = time();
$current = array(
	'version' => '0.4.0-test',
	'source_commit_sha' => str_repeat( 'b', 40 ),
	'build_fingerprint' => str_repeat( 'c', 64 ),
	'package_manifest_digest' => str_repeat( 'd', 64 ),
	'artifact_identity' => 'test-new-build',
);
$old = array(
	'source' => 'self_update', 'state' => 'pending_manual_resume',
	'resume_blocker' => 'mad4b_automation_automation_kill_switch',
	'target_identity' => array(
		'version' => '0.4.0-test', 'source_commit_sha' => str_repeat( 'a', 40 ),
		'build_fingerprint' => str_repeat( 'e', 64 ),
		'package_manifest_digest' => str_repeat( 'f', 64 ),
	),
);
$graph = array(
	'state' => 'ready', 'ready' => true, 'environment' => 'staging',
	'current_identity' => $current, 'required_blockers' => array(),
	'checkpoint' => $old,
	'checkpoint_operator_state' => MAD4B_SCP_Runtime_Convergence::checkpoint_operator_state( $old, $current, array() ),
);
$eligible = static function ( $checkpoint, $identity, $not_before, $state ) use ( $method ) {
	return (bool) $method->invoke( null, $checkpoint, $identity, $not_before, $state );
};
must( $eligible( $old, $current, $now - 40, $graph ), 'old paused checkpoint + independent READY graph becomes audit-only' );
must( 'mad4b_automation_automation_kill_switch' === $old['resume_blocker'], 'kill switch remains unchanged' );

foreach ( array( 'production', 'unknown' ) as $environment ) {
	$changed = $graph; $changed['environment'] = $environment;
	must( ! $eligible( $old, $current, $now - 40, $changed ), 'no auto-release in ' . $environment );
}
$blocked = $graph; $blocked['ready'] = false; $blocked['state'] = 'convergence_required';
must( ! $eligible( $old, $current, $now - 40, $blocked ), 'unready runtime stays blocked' );
$blocked = $graph; $blocked['required_blockers'] = array( 'schema:pending' );
must( ! $eligible( $old, $current, $now - 40, $blocked ), 'independent required blockers stay protected' );
must( ! $eligible( $old, $current, $now + 20, $graph ), 'quiet period remains protected' );

$matching = $old; $matching['target_identity']['source_commit_sha'] = $current['source_commit_sha'];
$matching_graph = $graph; $matching_graph['checkpoint'] = $matching;
must( ! $eligible( $matching, $current, $now - 40, $matching_graph ), 'matching SHA never bypasses real pending update' );
foreach ( array( 'pending_restart', 'pending_safe_phases', 'waiting_for_exact_runtime_restart', 'blocked' ) as $state ) {
	$other = $old; $other['state'] = $state;
	$other_graph = $graph; $other_graph['checkpoint'] = $other;
	must( ! $eligible( $other, $current, $now - 40, $other_graph ), $state . ' must remain fail-closed' );
}
$unknown = $current; $unknown['source_commit_sha'] = '';
must( ! $eligible( $old, $unknown, $now - 40, $graph ), 'unknown current identity stays protected' );
$drift = $graph; $drift['current_identity']['build_fingerprint'] = str_repeat( '0', 64 );
must( ! $eligible( $old, $current, $now - 40, $drift ), 'graph identity drift stays protected' );
$drift = $graph; $drift['checkpoint']['target_identity']['source_commit_sha'] = str_repeat( '9', 40 );
must( ! $eligible( $old, $current, $now - 40, $drift ), 'checkpoint changed during observation stays protected' );
$drift = $graph; $drift['checkpoint_operator_state']['state'] = 'historical_checkpoint_independent_blockers';
must( ! $eligible( $old, $current, $now - 40, $drift ), 'official checkpoint classifier remains authoritative' );
$drift = $graph; $drift['checkpoint_operator_state']['replay_old_checkpoint_allowed'] = true;
must( ! $eligible( $old, $current, $now - 40, $drift ), 'historical update must never be replayable' );
echo "RUNTIME_SUPERSEDED_RESTART_CHECKPOINT: PASS\n";
