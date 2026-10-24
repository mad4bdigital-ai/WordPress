<?php
/**
 * Regression: historical self-update only admits the read-only MCP route.
 * The decision helper does not mutate options or authorize a WP user.
 */
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );
function add_action( $name, $callback, $priority = 10 ) { return true; }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-runtime-convergence.php';
function require_read_gate( $pass, $reason ) {
	if ( ! $pass ) { fwrite( STDERR, 'READ_GATE_FAIL: ' . $reason . PHP_EOL ); exit( 1 ); }
}
$ref = new ReflectionMethod( 'MAD4B_SCP_Runtime_Convergence', 'superseded_checkpoint_mcp_read_safe' );
if ( PHP_VERSION_ID < 80100 ) $ref->setAccessible( true );
$now = time();
$current = array(
	'version' => '0.4.0-rc.103',
	'source_commit_sha' => str_repeat( 'b', 40 ),
	'build_fingerprint' => str_repeat( 'c', 64 ),
	'package_manifest_digest' => str_repeat( 'd', 64 ),
	'artifact_identity' => 'current-test-artifact',
);
$checkpoint = array(
	'source' => 'self_update',
	'state' => 'pending_manual_resume',
	'resume_blocker' => 'mad4b_automation_automation_kill_switch',
	'target_identity' => array(
		'version' => '0.4.0-rc.102',
		'source_commit_sha' => str_repeat( 'a', 40 ),
		'build_fingerprint' => str_repeat( 'e', 64 ),
		'package_manifest_digest' => str_repeat( 'f', 64 ),
	),
);
$graph = array(
	'state' => 'convergence_required',
	'ready' => false,
	'environment' => 'staging',
	'phase_graph_valid' => true,
	'current_identity' => $current,
	'required_blockers' => array( 'authority_binding:owner_gate' ),
	'checkpoint' => $checkpoint,
	'checkpoint_operator_state' => MAD4B_SCP_Runtime_Convergence::checkpoint_operator_state(
		$checkpoint, $current, array( 'authority_binding:owner_gate' )
	),
	'phases' => array(
		'deployment' => array( 'state' => 'ready', 'required' => true ),
		'schema' => array( 'state' => 'ready', 'required' => true ),
		'local_oauth_store' => array( 'state' => 'ready', 'required' => true ),
		'managed_skills' => array( 'state' => 'ready', 'required' => true ),
		'authority_binding' => array( 'state' => 'owner_gate', 'required' => true ),
		'external_acceptance' => array( 'state' => 'ready', 'required' => false ),
	),
);
$allowed = static function ( $record, $identity, $not_before, $proof ) use ( $ref ) {
	return (bool) $ref->invoke( null, $record, $identity, $not_before, $proof );
};
require_read_gate( $allowed( $checkpoint, $current, $now - 60, $graph ),
	'only stale checkpoint plus write owner gate permits guarded read' );
$tests = array(
	'production' => static function ( &$g ) { $g['environment'] = 'production'; },
	'missing_phase_graph' => static function ( &$g ) { $g['phase_graph_valid'] = false; },
	'independent_schema_blocker' => static function ( &$g ) { $g['required_blockers'][] = 'schema:pending'; $g['phases']['schema']['state'] = 'pending'; },
	'independent_skills_blocker' => static function ( &$g ) { $g['phases']['managed_skills']['state'] = 'pending'; },
	'independent_oauth_blocker' => static function ( &$g ) { $g['phases']['local_oauth_store']['state'] = 'pending'; },
	'owner_gate_not_required' => static function ( &$g ) { $g['phases']['authority_binding']['required'] = false; },
	'owner_gate_not_active' => static function ( &$g ) { $g['phases']['authority_binding']['state'] = 'ready'; },
	'checkpoint_replay_possible' => static function ( &$g ) { $g['checkpoint_operator_state']['replay_old_checkpoint_allowed'] = true; },
	'checkpoint_target_changed' => static function ( &$g ) { $g['checkpoint']['target_identity']['source_commit_sha'] = str_repeat( '9', 40 ); },
	'current_identity_changed' => static function ( &$g ) { $g['current_identity']['build_fingerprint'] = str_repeat( '8', 64 ); },
);
foreach ( $tests as $name => $change ) {
	$invalid = $graph;
	$change( $invalid );
	require_read_gate( ! $allowed( $checkpoint, $current, $now - 60, $invalid ), 'fail closed: ' . $name );
}
require_read_gate( ! $allowed( $checkpoint, $current, $now + 30, $graph ), 'quiet period protected' );
$wrong_blocker = $checkpoint;
$wrong_blocker['resume_blocker'] = 'schema_missing';
require_read_gate( ! $allowed( $wrong_blocker, $current, $now - 60, $graph ), 'other manual blocker protected' );
$active = $checkpoint; $active['state'] = 'pending_restart';
require_read_gate( ! $allowed( $active, $current, $now - 60, $graph ), 'pending restart protected' );
$equal = $current; $equal['version'] = '0.4.0-rc.102';
$equal_graph = $graph; $equal_graph['current_identity'] = $equal;
require_read_gate( ! $allowed( $checkpoint, $equal, $now - 60, $equal_graph ), 'equal version blocked' );
$unknown = $current; $unknown['source_commit_sha'] = '';
require_read_gate( ! $allowed( $checkpoint, $unknown, $now - 60, $graph ), 'unknown build blocked' );
echo "RUNTIME_HISTORICAL_READ_ISOLATION: PASS\n";
