<?php
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );

$tmp = sys_get_temp_dir() . '/mad4b-restart-barrier-' . getmypid() . '-' . uniqid();
if ( ! mkdir( $tmp, 0700, true ) && ! is_dir( $tmp ) ) { fwrite( STDERR, "FAIL temp dir\n" ); exit( 1 ); }
define( 'MAD4B_SCP_DIR', rtrim( $tmp, '/\\' ) . '/' );
define( 'MAD4B_SCP_VERSION', '0.4.0-test' );

$GLOBALS['mad4b_test_options'] = array();
function sanitize_key( $v ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $v ) ); }
function sanitize_text_field( $v ) { return trim( strip_tags( (string) $v ) ); }
function absint( $v ) { return abs( (int) $v ); }
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['mad4b_test_options'] ) ? $GLOBALS['mad4b_test_options'][ $k ] : $d; }

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

$base['state'] = 'completed';
$GLOBALS['mad4b_test_options'][ MAD4B_SCP_Runtime_Convergence::CHECKPOINT_OPTION ] = $base;
$status = MAD4B_SCP_Runtime_Convergence::restart_grace_status();
ok( empty( $status['active'] ), 'completed convergence must open restart barrier' );

@unlink( MAD4B_SCP_DIR . 'MAD4B-BUILD-PROVENANCE.json' );
@rmdir( MAD4B_SCP_DIR );
echo "mad4b.runtime-restart-barrier.v1: PASS\n";
