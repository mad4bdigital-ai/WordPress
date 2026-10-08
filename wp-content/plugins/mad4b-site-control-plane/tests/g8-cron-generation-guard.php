<?php
/**
 * Hermetic runtime-generation/Cron target guard regression.
 *
 * Does not boot WordPress or invoke plugin lifecycle methods. Only the real
 * private automatic guard is called through reflection against a disposable
 * provenance file. Exact manual invocation remains unchanged.
 */
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );
$root = sys_get_temp_dir() . '/mad4b-g8-generation-' . getmypid() . '-' . bin2hex( random_bytes( 4 ) );
if ( ! mkdir( $root, 0700, true ) ) { fwrite( STDERR, "Cannot prepare disposable fixture\n" ); exit( 1 ); }
define( 'MAD4B_SCP_DIR', $root . '/' );
define( 'MAD4B_SCP_VERSION', '0.4.0-rc.96' );
$GLOBALS['g8_checkpoint'] = array();
$GLOBALS['g8_ticket_allowed'] = true;
function get_option( $name, $default = false ) {
	return 'mad4b_scp_runtime_convergence_v1' === $name ? $GLOBALS['g8_checkpoint'] : $default;
}
function sanitize_text_field( $value ) { return trim( (string) $value ); }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
class WP_Error {
	private $code;
	public function __construct( $code, $message = '' ) { $this->code = $code; }
	public function get_error_code() { return $this->code; }
}
class MAD4B_SCP_Automation_SLO {
	public static function ticket_allowed( $ticket ) {
		if ( 'exception' === ( $GLOBALS['g8_ticket_allowed'] ?? null ) )
			throw new RuntimeException( 'simulated partial update verifier exception' );
		if ( 'untrusted_false' === ( $GLOBALS['g8_ticket_allowed'] ?? null ) ) return false;
		return $GLOBALS['g8_ticket_allowed'] ? true : new WP_Error( 'mad4b_automation_ticket_not_live' );
	}
}
function g8_guard_assert( $okay, $reason ) {
	if ( ! $okay ) { fwrite( STDERR, 'FAIL: ' . $reason . PHP_EOL ); exit( 1 ); }
}

require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-runtime-convergence.php';
$target = array(
	'version' => MAD4B_SCP_VERSION, 'source_commit_sha' => str_repeat( 'a', 40 ),
	'build_fingerprint' => str_repeat( 'b', 64 ),
	'package_manifest_digest' => str_repeat( 'c', 64 ), 'artifact_identity' => 'ci-g8-disposable',
);
$path = MAD4B_SCP_DIR . 'MAD4B-BUILD-PROVENANCE.json';
$document = array(
	'control_plane_version' => $target['version'],
	'source_commit_sha' => $target['source_commit_sha'],
	'build_fingerprint' => $target['build_fingerprint'],
	'package_manifest_digest' => $target['package_manifest_digest'],
	'artifact_identity' => $target['artifact_identity'],
);
file_put_contents( $path, json_encode( $document ) );
$GLOBALS['g8_checkpoint'] = array( 'target_identity' => $target );
$ticket = array( 'generation' => hash( 'sha256', serialize( array( $target, $target ) ) ) );
$guard = new ReflectionMethod( 'MAD4B_SCP_Runtime_Convergence', 'guard_automatic_ticket' );
$guard->setAccessible( true );
$run = static function ( $row ) use ( $guard ) { return $guard->invoke( null, $row ); };
g8_guard_assert( true === $run( $ticket ), 'exact Cron target is accepted' );
class G8_Unexpected_Checkpoint_Object {
	public function __serialize() {
		$GLOBALS['g8_unexpected_checkpoint_serialized'] = true;
		return array( 'side_effect' => true );
	}
}
$untrusted_checkpoint = $GLOBALS['g8_checkpoint'];
$untrusted_checkpoint['target_identity']['unexpected'] = new G8_Unexpected_Checkpoint_Object();
$GLOBALS['g8_checkpoint'] = $untrusted_checkpoint;
$unsafe = $run( $ticket );
g8_guard_assert( is_wp_error( $unsafe )
	&& 'mad4b_automation_target_identity_invalid' === $unsafe->get_error_code()
	&& empty( $GLOBALS['g8_unexpected_checkpoint_serialized'] ),
	'extra executable checkpoint identity rejected without serialization' );
$GLOBALS['g8_checkpoint'] = array( 'target_identity' => $target );
$changed = $GLOBALS['g8_checkpoint'];
$changed['target_identity']['build_fingerprint'] = str_repeat( 'e', 64 );
$GLOBALS['g8_checkpoint'] = $changed;
$denied = $run( $ticket );
g8_guard_assert( is_wp_error( $denied ) && 'mad4b_automation_target_identity_drift' === $denied->get_error_code(),
	'checkpoint target drift stops already admitted worker' );
$GLOBALS['g8_checkpoint'] = array( 'target_identity' => $target );
$stale = $ticket; $stale['generation'] = str_repeat( '0', 64 );
$denied = $run( $stale );
g8_guard_assert( is_wp_error( $denied ) && 'mad4b_automation_checkpoint_generation_drift' === $denied->get_error_code(),
	'stale generation cannot follow a changed checkpoint' );
$document['build_fingerprint'] = str_repeat( 'd', 64 );
file_put_contents( $path, json_encode( $document ) );
$denied = $run( $ticket );
g8_guard_assert( is_wp_error( $denied ) && 'mad4b_automation_target_identity_drift' === $denied->get_error_code(),
	'on-disk runtime drift fails closed' );
g8_guard_assert( true === $run( null ), 'explicit governed/manual path remains separate' );
file_put_contents( $path, json_encode( array_merge( $document, array( 'build_fingerprint' => $target['build_fingerprint'] ) ) ) );
$GLOBALS['g8_ticket_allowed'] = false;
$denied = $run( $ticket );
g8_guard_assert( is_wp_error( $denied ) && 'mad4b_automation_ticket_not_live' === $denied->get_error_code(),
	'independent revoked ticket checked before candidate generation' );
$GLOBALS['g8_ticket_allowed'] = 'untrusted_false';
$denied = $run( $ticket );
g8_guard_assert( is_wp_error( $denied ) && 'mad4b_automation_ticket_denied' === $denied->get_error_code(),
	'non-true verifier result must never allow a mutation' );
$GLOBALS['g8_ticket_allowed'] = 'exception';
$denied = $run( $ticket );
g8_guard_assert( is_wp_error( $denied ) && 'mad4b_automation_ticket_verification_exception' === $denied->get_error_code(),
	'exception in partial update verifier must be a stable guard denial' );
@unlink( $path ); @rmdir( $root );
echo 'G8_CRON_GENERATION_GUARD: PASS' . PHP_EOL;
