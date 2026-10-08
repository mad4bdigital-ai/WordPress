<?php
/**
 * Fail-closed partial-plugin bootstrap regression. This new PHP process
 * intentionally does not define MAD4B_SCP_Automation_SLO; real Cron must park,
 * not treat the missing class as a governed/manual path.
 */
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );
$root = sys_get_temp_dir() . '/mad4b-g8-missing-' . getmypid() . '-' . bin2hex( random_bytes( 4 ) );
if ( ! mkdir( $root, 0700, true ) ) { fwrite( STDERR, 'Cannot prepare disposable fixture' . PHP_EOL ); exit( 1 ); }
define( 'MAD4B_SCP_DIR', $root . '/' );
define( 'MAD4B_SCP_VERSION', '0.4.0-rc.96' );
$GLOBALS['g8_checkpoint'] = array();
function get_option( $key, $default = false ) {
	return 'mad4b_scp_runtime_convergence_v1' === $key ? $GLOBALS['g8_checkpoint'] : $default;
}
function update_option( $key, $value, $autoload = false ) {
	if ( 'mad4b_scp_runtime_convergence_v1' === $key ) $GLOBALS['g8_checkpoint'] = $value;
	return true;
}
function sanitize_key( $v ) { return strtolower( preg_replace( '/[^a-z0-9_\\-]/', '', (string) $v ) ); }
function sanitize_text_field( $v ) { return trim( (string) $v ); }
function wp_get_environment_type() { return 'staging'; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
class WP_Error {
	private $code;
	public function __construct( $code, $message = '' ) { $this->code = $code; }
	public function get_error_code() { return $this->code; }
}
$throwing_slo = '1' === getenv( 'G8_THROWING_SLO' );
$throwing_worker = '1' === getenv( 'G8_THROWING_WORKER' );
if ( $throwing_worker ) {
	class MAD4B_SCP_Automation_SLO {
		public static function reserve( $provider, $capability, $generation ) {
			return array( 'token' => str_repeat( 'e', 32 ), 'generation' => $generation );
		}
		public static function ticket_allowed( $ticket ) { return true; }
		public static function finish_existing( $ticket, $result ) {
			$GLOBALS['g8_worker_finished_error'] = is_wp_error( $result ) ? $result->get_error_code() : 'not_error';
			return true;
		}
	}
	class MAD4B_SCP_Runtime_Maintenance_Lease {
		public static function acquire( $scope ) {
			throw new RuntimeException( 'simulated executor failure' );
		}
	}
}
if ( $throwing_slo ) {
	class MAD4B_SCP_Automation_SLO {
		public static function reserve( $provider, $capability, $generation ) {
			throw new RuntimeException( 'simulated internal admission failure' );
		}
		public static function ticket_allowed( $ticket ) { return true; }
		public static function finish_existing( $ticket, $result ) { return true; }
	}
}
require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-runtime-convergence.php';
$identity = array( 'version' => MAD4B_SCP_VERSION, 'source_commit_sha' => str_repeat( 'a', 40 ),
	'build_fingerprint' => str_repeat( 'b', 64 ), 'package_manifest_digest' => str_repeat( 'c', 64 ),
	'artifact_identity' => 'g8-missing-guard-disposable' );
file_put_contents( MAD4B_SCP_DIR . 'MAD4B-BUILD-PROVENANCE.json', json_encode( array(
	'control_plane_version' => $identity['version'],
	'source_commit_sha' => $identity['source_commit_sha'],
	'build_fingerprint' => $identity['build_fingerprint'],
	'package_manifest_digest' => $identity['package_manifest_digest'],
	'artifact_identity' => $identity['artifact_identity'],
) ) );
$GLOBALS['g8_checkpoint'] = array( 'contract' => MAD4B_SCP_Runtime_Convergence::CONTRACT,
	'source' => 'self_update_regression', 'state' => 'pending_safe_phases', 'target_identity' => $identity,
	'resume_not_before' => 0, 'automatic_retry_allowed' => true );
if ( ! $throwing_slo && ! $throwing_worker && class_exists( 'MAD4B_SCP_Automation_SLO', false ) ) { fwrite( STDERR, 'SLO class must not be bootstrapped for missing-class fixture' . PHP_EOL ); exit( 1 ); }
MAD4B_SCP_Runtime_Convergence::resume_safe_phases();
$checkpoint = $GLOBALS['g8_checkpoint'];
if ( $throwing_worker ) {
	if ( 'mad4b_automation_worker_exception' !== ( $GLOBALS['g8_worker_finished_error'] ?? null )
		|| 'blocked' !== ( $checkpoint['state'] ?? null )
		|| false !== ( $checkpoint['automatic_retry_allowed'] ?? null ) ) {
		fwrite( STDERR, 'FAIL: throwing worker did not settle ticket and block retries' . PHP_EOL ); exit( 1 );
	}
} elseif ( 'pending_manual_resume' !== ( $checkpoint['state'] ?? null )
	|| ( $throwing_slo ? 'mad4b_automation_admission_exception' : 'mad4b_automation_guard_missing' ) !== ( $checkpoint['resume_blocker'] ?? null )
	|| false !== ( $checkpoint['automatic_retry_allowed'] ?? null ) ) {
	fwrite( STDERR, 'FAIL: missing automatic security class was not parked' . PHP_EOL ); exit( 1 );
}
@unlink( MAD4B_SCP_DIR . 'MAD4B-BUILD-PROVENANCE.json' ); @rmdir( $root );
echo $throwing_worker ? 'G8_CRON_THROWING_WORKER: PASS' . PHP_EOL
	: ( $throwing_slo ? 'G8_CRON_THROWING_GUARD: PASS' . PHP_EOL : 'G8_CRON_MISSING_GUARD: PASS' . PHP_EOL );
