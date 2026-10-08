<?php
/**
 * Hermetic runtime-generation/Cron target guard regression.
 *
 * Does not boot WordPress. Calls the real automatic guard and safe-phase worker
 * through reflection against a disposable provenance file and effect stubs.
 * Exact manual invocation remains unchanged.
 */
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );
$root = sys_get_temp_dir() . '/mad4b-g8-generation-' . getmypid() . '-' . bin2hex( random_bytes( 4 ) );
if ( ! mkdir( $root, 0700, true ) ) { fwrite( STDERR, "Cannot prepare disposable fixture\n" ); exit( 1 ); }
define( 'MAD4B_SCP_DIR', $root . '/' );
define( 'MAD4B_SCP_VERSION', '0.4.0-rc.96' );
$GLOBALS['g8_checkpoint'] = array();
$GLOBALS['g8_ticket_allowed'] = true;
function get_option( $name, $default = false ) {
	if ( 'mad4b_scp_runtime_convergence_v1' === $name && is_callable( $GLOBALS['g8_checkpoint_read_hook'] ?? null ) ) {
		$callback = $GLOBALS['g8_checkpoint_read_hook']; $GLOBALS['g8_checkpoint_read_hook'] = null; $callback();
	}
	return 'mad4b_scp_runtime_convergence_v1' === $name ? $GLOBALS['g8_checkpoint'] : $default;
}
// Loading Runtime Convergence registers its existing lifecycle hooks.
function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) { return true; }
function sanitize_text_field( $value ) { return trim( (string) $value ); }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
class WP_Error {
	private $code;
	public function __construct( $code, $message = '' ) { $this->code = $code; }
	public function get_error_code() { return $this->code; }
}
class MAD4B_SCP_Automation_SLO {
	public static function additional_scope_allowed( $ticket, $provider, $capability ) {
		if ( ! empty( $GLOBALS['g8_skills_scope_paused'] ) ) return new WP_Error( 'mad4b_automation_kill_switch' );
		return true;
	}
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

require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-g8-record.php';
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
$GLOBALS['g8_checkpoint'] = array( 'target_identity' => $target,
	'state' => 'pending_safe_phases', 'automatic_retry_allowed' => true );
$ticket = array( 'token' => str_repeat( 'f', 32 ),
	'generation' => hash( 'sha256', serialize( array( $target, $target ) ) ) );
$guard = new ReflectionMethod( 'MAD4B_SCP_Runtime_Convergence', 'guard_automatic_ticket' );
$guard->setAccessible( true );
$run = static function ( $row ) use ( $guard ) { return $guard->invoke( null, $row ); };
g8_guard_assert( true === $run( $ticket ), 'exact Cron target is accepted' );
$blocked = $GLOBALS['g8_checkpoint'];
$blocked['state'] = 'blocked';
$GLOBALS['g8_checkpoint'] = $blocked;
$denied = $run( $ticket );
g8_guard_assert( is_wp_error( $denied )
	&& 'mad4b_automation_checkpoint_not_schedulable' === $denied->get_error_code(),
	'midflight owner pause revokes an otherwise valid generation-bound ticket' );
$completed = $blocked;
$completed['state'] = 'completed';
$completed['g8_completion_ticket_sha256'] = hash( 'sha256', $ticket['token'] );
$completed['g8_completion_generation'] = $ticket['generation'];
$GLOBALS['g8_checkpoint'] = $completed;
g8_guard_assert( true === $run( $ticket ), 'exact completed checkpoint author permits late owned metadata only' );
$completed['g8_completion_ticket_sha256'] = str_repeat( '0', 64 );
$GLOBALS['g8_checkpoint'] = $completed;
$denied = $run( $ticket );
g8_guard_assert( is_wp_error( $denied )
	&& 'mad4b_automation_checkpoint_not_schedulable' === $denied->get_error_code(),
	'another worker completion cannot be borrowed for late mutations' );
$GLOBALS['g8_checkpoint'] = array( 'target_identity' => $target,
	'state' => 'pending_safe_phases', 'automatic_retry_allowed' => true );
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
$GLOBALS['g8_checkpoint'] = array( 'target_identity' => $target,
	'state' => 'pending_safe_phases', 'automatic_retry_allowed' => true );
$changed = $GLOBALS['g8_checkpoint'];
$changed['target_identity']['build_fingerprint'] = str_repeat( 'e', 64 );
$GLOBALS['g8_checkpoint'] = $changed;
$denied = $run( $ticket );
g8_guard_assert( is_wp_error( $denied ) && 'mad4b_automation_target_identity_drift' === $denied->get_error_code(),
	'checkpoint target drift stops already admitted worker' );
$GLOBALS['g8_checkpoint'] = array( 'target_identity' => $target,
	'state' => 'pending_safe_phases', 'automatic_retry_allowed' => true );
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
$GLOBALS['g8_ticket_allowed'] = true;
$GLOBALS['g8_skills_scope_paused'] = true;
$denied = $guard->invoke( null, $ticket, 'reconcile' );
g8_guard_assert( is_wp_error( $denied ) && 'mad4b_automation_kill_switch' === $denied->get_error_code(),
	'real automatic phase guard rejects a managed Skills pause inside a generic ticket' );
$denied = $guard->invoke( null, $ticket, 'certify' );
g8_guard_assert( is_wp_error( $denied ) && 'mad4b_automation_kill_switch' === $denied->get_error_code(),
	'persisting Skills certification also obeys the managed Skills scope' );
g8_guard_assert( true === $guard->invoke( null, null, 'reconcile' ),
	'explicit governed manual reconciliation retains its separate authority path' );
$GLOBALS['g8_skills_scope_paused'] = false;
$GLOBALS['g8_checkpoint_read_hook'] = static function () { $GLOBALS['g8_ticket_allowed'] = false; };
$denied = $run( $ticket );
g8_guard_assert( is_wp_error( $denied ) && 'mad4b_automation_ticket_not_live' === $denied->get_error_code(),
	'pause during checkpoint readback revokes the next automatic effect' );
$GLOBALS['g8_ticket_allowed'] = true;
$GLOBALS['g8_checkpoint_read_hook'] = static function () { $GLOBALS['g8_skills_scope_paused'] = true; };
$denied = $guard->invoke( null, $ticket, 'reconcile' );
g8_guard_assert( is_wp_error( $denied ) && 'mad4b_automation_kill_switch' === $denied->get_error_code(),
	'scoped pause during checkpoint readback revokes the next managed-file effect' );

// Exercise the real worker boundary, not only its private ticket validator:
// adapter registration can invoke discovery hooks before the first seed write.
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) ); }
class MAD4B_SCP_Runtime_Maintenance_Lease {
	public static function acquire( $owner ) { return 'g8-disposable-runtime-lease'; }
	public static function refresh( $lease, $owner ) { return true; }
	public static function release( $lease, $owner ) {}
}
class MAD4B_SCP_Schema { public static function status( $physical = false ) { return array( 'ready' => true ); } }
class MAD4B_SCP_Site_Profile { public static function status() { return array( 'skills_enabled' => true ); } }
class MAD4B_SCP_Skill_Runtime_Certification { public static function current_status() { return array( 'ready' => false ); } }
class MAD4B_SCP_Adapter_Registry {
	public static function instance() { return new self(); }
	public function register_defaults() { $GLOBALS['g8_skills_scope_paused'] = true; }
}
class MAD4B_SCP_Skill_Seeder {
	public static function reconcile() { ++$GLOBALS['g8_seed_effects']; return array(); }
}
class MAD4B_SCP_Skill_Provider_Discovery {
	public static function reconcile() { ++$GLOBALS['g8_provider_effects']; return array(); }
}
$GLOBALS['g8_ticket_allowed'] = true; $GLOBALS['g8_skills_scope_paused'] = false;
$GLOBALS['g8_seed_effects'] = 0; $GLOBALS['g8_provider_effects'] = 0;
$worker = new ReflectionMethod( 'MAD4B_SCP_Runtime_Convergence', 'run_safe_phases' );
$worker->setAccessible( true );
$denied = $worker->invoke( null, 'post_update_cron', array(), $ticket );
g8_guard_assert( is_wp_error( $denied ) && 'mad4b_automation_kill_switch' === $denied->get_error_code()
	&& 0 === $GLOBALS['g8_seed_effects'] && 0 === $GLOBALS['g8_provider_effects'],
	'pause from adapter discovery stops the real worker before any seed/provider effect' );
@unlink( $path ); @rmdir( $root );
echo 'G8_CRON_GENERATION_GUARD: PASS' . PHP_EOL;
