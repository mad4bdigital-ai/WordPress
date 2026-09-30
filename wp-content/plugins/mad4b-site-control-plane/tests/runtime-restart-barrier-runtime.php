<?php
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );

$tmp = sys_get_temp_dir() . '/mad4b-restart-barrier-' . getmypid() . '-' . uniqid();
if ( ! mkdir( $tmp, 0700, true ) && ! is_dir( $tmp ) ) { fwrite( STDERR, "FAIL temp dir\n" ); exit( 1 ); }
define( 'MAD4B_SCP_DIR', rtrim( $tmp, '/\\' ) . '/' );
define( 'MAD4B_SCP_VERSION', '0.4.0-test' );

$GLOBALS['mad4b_test_options'] = array();
$GLOBALS['mad4b_test_checkpoint_write_mode'] = 'persist';
$GLOBALS['mad4b_test_schedule_result'] = true;
function sanitize_key( $v ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $v ) ); }
function sanitize_text_field( $v ) { return trim( strip_tags( (string) $v ) ); }
function absint( $v ) { return abs( (int) $v ); }
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['mad4b_test_options'] ) ? $GLOBALS['mad4b_test_options'][ $k ] : $d; }
function update_option( $k, $v, $autoload = null ) {
	if ( 'mad4b_scp_runtime_convergence_v1' === $k ) {
		$mode = isset( $GLOBALS['mad4b_test_checkpoint_write_mode'] ) ? (string) $GLOBALS['mad4b_test_checkpoint_write_mode'] : 'persist';
		if ( 'drop_all' === $mode ) return false;
		if ( 'drop_manual' === $mode && is_array( $v ) && isset( $v['state'] ) && 'pending_manual_resume' === $v['state'] ) return false;
	}
	$GLOBALS['mad4b_test_options'][ $k ] = $v;
	return true;
}
function wp_next_scheduled( $hook ) { return false; }
function wp_schedule_single_event( $timestamp, $hook, $args = array(), $wp_error = false ) { return ! empty( $GLOBALS['mad4b_test_schedule_result'] ); }
function wp_clear_scheduled_hook( $hook ) { return true; }
function is_wp_error( $value ) { return false; }
final class MAD4B_SCP_Environment { public static function effective() { return 'staging'; } }
function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) { return true; }

$actual = array(
	'contract' => 'mad4b.build-provenance.v1',
	'control_plane_version' => '0.4.0-test',
	'source_commit_sha' => str_repeat( 'a', 40 ),
	'build_fingerprint' => str_repeat( 'b', 64 ),
	'package_manifest_digest' => str_repeat( 'c', 64 ),
);
file_put_contents( MAD4B_SCP_DIR . 'MAD4B-BUILD-PROVENANCE.json', json_encode( $actual ) );

require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-runtime-convergence.php';

function ok( $condition, $message ) {
	if ( ! $condition ) { fwrite( STDERR, "FAIL: {$message}\n" ); exit( 1 ); }
}

$base = array(
	'contract' => MAD4B_SCP_Runtime_Convergence::CONTRACT,
	'source' => 'self_update',
	'state' => 'waiting_for_exact_runtime_restart',
	'resume_not_before' => time() - 100,
	'target_identity' => array(
		'version' => '0.4.0-test',
		'source_commit_sha' => str_repeat( 'd', 40 ),
		'build_fingerprint' => str_repeat( 'e', 64 ),
		'package_manifest_digest' => str_repeat( 'f', 64 ),
	),
);
$GLOBALS['mad4b_test_options'][ MAD4B_SCP_Runtime_Convergence::CHECKPOINT_OPTION ] = $base;
$status = MAD4B_SCP_Runtime_Convergence::restart_grace_status();
ok( ! empty( $status['active'] ), 'expired quiet period must not open transport while convergence is pending' );
ok( empty( $status['quiet_period_active'] ), 'quiet period is expired in T+21 scenario' );
ok( empty( $status['exact_runtime_identity_match'] ), 'mismatched target must remain visible' );
ok( ! empty( $status['retryable'] ) && $status['retry_after_seconds'] > 0, 'identity mismatch should fail fast with bounded retry guidance' );

$base['state'] = 'pending_safe_phases';
$base['target_identity'] = array(
	'version' => $actual['control_plane_version'],
	'source_commit_sha' => $actual['source_commit_sha'],
	'build_fingerprint' => $actual['build_fingerprint'],
	'package_manifest_digest' => $actual['package_manifest_digest'],
);
$GLOBALS['mad4b_test_options'][ MAD4B_SCP_Runtime_Convergence::CHECKPOINT_OPTION ] = $base;
$status = MAD4B_SCP_Runtime_Convergence::restart_grace_status();
ok( ! empty( $status['active'] ), 'matching identity alone must not open transport before safe phases complete' );
ok( ! empty( $status['exact_runtime_identity_match'] ), 'matching exact runtime identity must be reported' );

$base['state'] = 'pending_manual_resume';
$GLOBALS['mad4b_test_options'][ MAD4B_SCP_Runtime_Convergence::CHECKPOINT_OPTION ] = $base;
$status = MAD4B_SCP_Runtime_Convergence::restart_grace_status();
ok( ! empty( $status['active'] ) && empty( $status['retryable'] ), 'manual resume state must remain closed without retry loop' );
ok( 'operator_resume_runtime_convergence' === $status['client_action'], 'manual resume must tell client operator action is required' );
ok( 0 === $status['retry_after_seconds'], 'manual resume must not advertise a time-only retry' );

$base['state'] = 'blocked';
$GLOBALS['mad4b_test_options'][ MAD4B_SCP_Runtime_Convergence::CHECKPOINT_OPTION ] = $base;
$status = MAD4B_SCP_Runtime_Convergence::restart_grace_status();
ok( ! empty( $status['active'] ), 'failed self-update safe convergence must keep MCP transport closed' );
ok( empty( $status['retryable'] ), 'blocked convergence must not create an automatic retry loop' );
ok( 0 === $status['retry_after_seconds'], 'blocked convergence must not advertise time-only recovery' );
ok( 'operator_repair_runtime_convergence' === $status['client_action'], 'blocked convergence must require explicit operator repair' );

$base['state'] = 'completed';
$GLOBALS['mad4b_test_options'][ MAD4B_SCP_Runtime_Convergence::CHECKPOINT_OPTION ] = $base;
$status = MAD4B_SCP_Runtime_Convergence::restart_grace_status();
ok( empty( $status['active'] ), 'completed convergence must open restart barrier' );

// Exact post-update persistence must reject a stale checkpoint even when it
// carries the same target identity. Identity equality alone is insufficient.
$target = array(
	'version' => $actual['control_plane_version'],
	'source_commit_sha' => $actual['source_commit_sha'],
	'build_fingerprint' => $actual['build_fingerprint'],
	'package_manifest_digest' => $actual['package_manifest_digest'],
);
$stale = array(
	'contract' => MAD4B_SCP_Runtime_Convergence::CONTRACT,
	'source' => 'self_update',
	'state' => 'completed',
	'target_identity' => $target,
	'channel' => 'governed_native_release_pull',
	'update_plan_sha256' => str_repeat( '1', 64 ),
	'resume_not_before' => time() - 100,
	'quiet_period_seconds' => MAD4B_SCP_Runtime_Convergence::POST_UPDATE_QUIET_SECONDS,
	'production_mutation' => false,
);
$GLOBALS['mad4b_test_options'][ MAD4B_SCP_Runtime_Convergence::CHECKPOINT_OPTION ] = $stale;
$GLOBALS['mad4b_test_checkpoint_write_mode'] = 'drop_all';
$GLOBALS['mad4b_test_schedule_result'] = true;
$persist = MAD4B_SCP_Runtime_Convergence::mark_post_update_pending( $target, 'governed_native_release_pull', str_repeat( '1', 64 ) );
ok( 'checkpoint_persist_failed' === $persist['state'], 'stale same-build checkpoint must not satisfy exact post-update persistence' );
ok( 'pending_restart' === $persist['persist_phase'], 'initial persistence failure must identify pending_restart phase' );
ok( 'completed' === $GLOBALS['mad4b_test_options'][ MAD4B_SCP_Runtime_Convergence::CHECKPOINT_OPTION ]['state'], 'failed write must leave stale checkpoint visible rather than being accepted' );

// Initial pending_restart may persist while Cron scheduling fails. The follow-up
// pending_manual_resume transition is also durability-critical and must verify.
$GLOBALS['mad4b_test_options'] = array();
$GLOBALS['mad4b_test_checkpoint_write_mode'] = 'drop_manual';
$GLOBALS['mad4b_test_schedule_result'] = false;
$manual = MAD4B_SCP_Runtime_Convergence::mark_post_update_pending( $target, 'governed_native_release_pull', str_repeat( '2', 64 ) );
ok( 'checkpoint_persist_failed' === $manual['state'], 'unpersisted manual-resume state must fail closed' );
ok( 'pending_manual_resume' === $manual['persist_phase'], 'manual persistence failure must identify its exact phase' );
ok( 'pending_restart' === $GLOBALS['mad4b_test_options'][ MAD4B_SCP_Runtime_Convergence::CHECKPOINT_OPTION ]['state'], 'failed manual transition must retain durable pending_restart barrier' );

$GLOBALS['mad4b_test_checkpoint_write_mode'] = 'persist';
$GLOBALS['mad4b_test_schedule_result'] = true;


@unlink( MAD4B_SCP_DIR . 'MAD4B-BUILD-PROVENANCE.json' );
@rmdir( MAD4B_SCP_DIR );
echo "mad4b.runtime-restart-barrier.v1: PASS\n";
