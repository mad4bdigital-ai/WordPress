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
if ( PHP_VERSION_ID < 80100 ) $method->setAccessible( true );
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
// A cached release from the superseded checkpoint must not indefinitely
// block a strictly newer installed package when it is the ONLY core blocker.
$new = $current; $new['version'] = '0.4.0-rc.103';
$previous = $old; $previous['target_identity']['version'] = '0.4.0-rc.102';
$stale = $graph;
$stale['state'] = 'convergence_required';
$stale['ready'] = false;
$stale['environment'] = 'staging';
$stale['current_identity'] = $new;
$stale['checkpoint'] = $previous;
$stale['required_blockers'] = array( 'deployment:pending' );
$stale['phase_graph_valid'] = true;
$stale['phases'] = array(
	'deployment' => array(
		'required' => true, 'state' => 'pending',
		'details' => array(
			'cached_release_target_present' => true,
			'manifest_state' => 'ready',
			'target_identity' => $previous['target_identity'],
		),
	),
	'schema' => array( 'required' => true, 'state' => 'ready' ),
	'managed_skills' => array( 'required' => true, 'state' => 'ready' ),
	'performance_advisory' => array( 'required' => false, 'state' => 'advisory' ),
);
$stale['checkpoint_operator_state'] = MAD4B_SCP_Runtime_Convergence::checkpoint_operator_state(
	$previous, $new, $stale['required_blockers']
);
must( $eligible( $previous, $new, $now - 40, $stale ), 'exact stale cache-only blocker releases historical grace' );
// Live restored ARE evidence: self-update checkpoint SHA and validated cached
// manifest SHA differ, but both certified identities are for old rc.102.
// This must release only after installing rc.103 with no other blockers.
$dual_old_releases = $stale;
$dual_old_releases['phases']['deployment']['details']['target_identity']['source_commit_sha'] = str_repeat( '9', 40 );
$dual_old_releases['phases']['deployment']['details']['target_identity']['build_fingerprint'] = str_repeat( '1', 64 );
$dual_old_releases['phases']['deployment']['details']['target_identity']['package_manifest_digest'] = str_repeat( '2', 64 );
must( $eligible( $previous, $new, $now - 40, $dual_old_releases ),
	'different complete old release SHA at same rc.102 cannot block a strictly newer rc.103 with deployment-only blocker' );
$missing_cache_fingerprint = $dual_old_releases;
unset( $missing_cache_fingerprint['phases']['deployment']['details']['target_identity']['build_fingerprint'] );
must( ! $eligible( $previous, $new, $now - 40, $missing_cache_fingerprint ),
	'different old release SHA without complete fingerprint stays blocked' );
$missing_checkpoint_digest = $previous;
unset( $missing_checkpoint_digest['target_identity']['package_manifest_digest'] );
$missing_checkpoint_graph = $dual_old_releases;
$missing_checkpoint_graph['checkpoint'] = $missing_checkpoint_digest;
must( ! $eligible( $missing_checkpoint_digest, $new, $now - 40, $missing_checkpoint_graph ),
	'different old release SHA without complete checkpoint digest stays blocked' );
$independent_schema = $dual_old_releases;
$independent_schema['required_blockers'][] = 'schema:pending';
$independent_schema['phases']['schema']['state'] = 'pending';
must( ! $eligible( $previous, $new, $now - 40, $independent_schema ),
	'independent schema blocker cannot be bypassed by different historical SHA' );
$cache_cases = array(
	'no_cache_certification' => static function ( &$g ) { $g['phases']['deployment']['details']['cached_release_target_present'] = false; },
	'unready_manifest' => static function ( &$g ) { $g['phases']['deployment']['details']['manifest_state'] = 'not_cached'; },
	'invalid_cached_sha' => static function ( &$g ) { $g['phases']['deployment']['details']['target_identity']['source_commit_sha'] = 'not-a-sha'; },
	'different_cached_version' => static function ( &$g ) { $g['phases']['deployment']['details']['target_identity']['version'] = '0.4.0-rc.100'; },
	'independent_schema_blocker' => static function ( &$g ) { $g['required_blockers'][] = 'schema:pending'; $g['phases']['schema']['state'] = 'pending'; },
	'phase_graph_invalid' => static function ( &$g ) { $g['phase_graph_valid'] = false; },
	'not_really_pending' => static function ( &$g ) { $g['phases']['deployment']['state'] = 'ready'; },
	'production_environment' => static function ( &$g ) { $g['environment'] = 'production'; },
	'no_other_required_ready' => static function ( &$g ) { $g['phases']['managed_skills']['state'] = 'pending'; },
	'readiness_not_classified' => static function ( &$g ) { $g['checkpoint_operator_state']['state'] = 'checkpoint_identity_unverified'; },
	'replay_enabled' => static function ( &$g ) { $g['checkpoint_operator_state']['replay_old_checkpoint_allowed'] = true; },
);
foreach ( $cache_cases as $label => $mutate ) {
	$invalid = $stale;
	$mutate( $invalid );
	must( ! $eligible( $previous, $new, $now - 40, $invalid ), 'cache exception must fail closed: ' . $label );
}
$not_newer = $new; $not_newer['version'] = '0.4.0-rc.102';
$same_graph = $stale; $same_graph['current_identity'] = $not_newer;
must( ! $eligible( $previous, $not_newer, $now - 40, $same_graph ), 'equal version cannot bypass active checkpoint' );
$no_old_version = $previous; $no_old_version['target_identity']['version'] = '';
$no_old_graph = $stale; $no_old_graph['checkpoint'] = $no_old_version;
must( ! $eligible( $no_old_version, $new, $now - 40, $no_old_graph ), 'unknown checkpoint version remains blocked' );
echo "RUNTIME_SUPERSEDED_RESTART_CHECKPOINT: PASS\n";
