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
function maybe_serialize( $value ) { return is_array( $value ) || is_object( $value ) ? serialize( $value ) : $value; }
function wp_cache_delete( $key, $group = '' ) { return true; }
class G8_Checkpoint_Disposable_WPDB {
	public $options = 'wp_options';
	public function prepare( $sql, ...$args ) { return $args; }
	public function query( $args ) {
		list( $next, $key, $expected ) = $args;
		if ( 'mad4b_scp_runtime_convergence_v1' !== $key ) return false;
		if ( '1' === getenv( 'G8_CHECKPOINT_CAS_RACE' ) ) {
			// Another operator changes the checkpoint between read and SQL CAS.
			$GLOBALS['g8_checkpoint']['state'] = 'blocked';
			$GLOBALS['g8_checkpoint']['automatic_retry_allowed'] = false;
		}
		if ( maybe_serialize( $GLOBALS['g8_checkpoint'] ) !== $expected ) return 0;
		$GLOBALS['g8_checkpoint'] = unserialize( $next );
		return 1;
	}
}
$GLOBALS['wpdb'] = new G8_Checkpoint_Disposable_WPDB();
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
require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-g8-record.php';
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
$pending_restart = '1' === getenv( 'G8_PENDING_RESTART' );
if ( $pending_restart ) {
	$GLOBALS['g8_checkpoint']['state'] = 'pending_restart';
	unset( $GLOBALS['g8_checkpoint']['automatic_retry_allowed'] );
}
$stale_state = getenv( 'G8_STALE_CRON_STATE' ) ?: '';
$paused_cron = '1' === getenv( 'G8_STALE_CRON_PAUSED' );
if ( '' !== $stale_state ) {
	if ( ! in_array( $stale_state, array( 'blocked', 'completed', 'pending_manual_resume', 'waiting_for_exact_runtime_restart' ), true ) ) {
		fwrite( STDERR, 'Invalid stale Cron fixture state' . PHP_EOL ); exit( 2 );
	}
	$GLOBALS['g8_checkpoint']['state'] = $stale_state;
}
if ( $paused_cron ) $GLOBALS['g8_checkpoint']['automatic_retry_allowed'] = false;
$malicious_checkpoint = '1' === getenv( 'G8_MALICIOUS_CHECKPOINT' );
if ( $malicious_checkpoint ) {
	$GLOBALS['g8_checkpoint']['untrusted_extra'] = new class {
		public function __serialize() {
			$GLOBALS['g8_executable_checkpoint_serialized'] = true;
			return array( 'side_effect' => true );
		}
	};
}
$before_stale = $GLOBALS['g8_checkpoint'];
$gate = MAD4B_SCP_Runtime_Convergence::automatic_checkpoint_gate();
$expected_gate = $malicious_checkpoint ? 'checkpoint_untrusted_data'
	: ( '' !== $stale_state ? 'checkpoint_state_not_scheduled'
	: ( $paused_cron ? 'checkpoint_automatic_retry_paused' : 'awaiting_independent_slo_ticket' ) );
if ( $expected_gate !== ( $gate['reason'] ?? '' )
	|| ( 'awaiting_independent_slo_ticket' === $expected_gate ) !== ( $gate['checkpoint_schedulable'] ?? null )
	|| false !== ( $gate['slo_ticket_verified'] ?? null ) ) {
	fwrite( STDERR, 'FAIL: read-only Cron gate reported the wrong checkpoint admission reason' . PHP_EOL );
	exit( 1 );
}
if ( ! $throwing_slo && ! $throwing_worker && class_exists( 'MAD4B_SCP_Automation_SLO', false ) ) { fwrite( STDERR, 'SLO class must not be bootstrapped for missing-class fixture' . PHP_EOL ); exit( 1 ); }
MAD4B_SCP_Runtime_Convergence::resume_safe_phases();
$checkpoint = $GLOBALS['g8_checkpoint'];
if ( '1' === getenv( 'G8_CHECKPOINT_CAS_RACE' ) ) {
	if ( 'blocked' !== ( $checkpoint['state'] ?? '' )
		|| false !== ( $checkpoint['automatic_retry_allowed'] ?? null )
		|| isset( $checkpoint['resume_blocker'] ) ) {
		fwrite( STDERR, 'FAIL: lost SQL CAS overwrote the operator block' . PHP_EOL ); exit( 1 );
	}
	echo 'G8_CHECKPOINT_ATOMIC_RACE: PASS' . PHP_EOL;
	exit( 0 );
}
if ( '' !== $stale_state || $paused_cron || $malicious_checkpoint ) {
	if ( $before_stale !== $checkpoint || ! empty( $GLOBALS['g8_worker_finished_error'] )
		|| ! empty( $GLOBALS['g8_executable_checkpoint_serialized'] ) ) {
		fwrite( STDERR, 'FAIL: stale or paused Cron event mutated its checkpoint' . PHP_EOL );
		exit( 1 );
	}
	@unlink( MAD4B_SCP_DIR . 'MAD4B-BUILD-PROVENANCE.json' ); @rmdir( $root );
	echo 'G8_CRON_STALE_OR_PAUSED: PASS' . PHP_EOL;
	exit( 0 );
}
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
