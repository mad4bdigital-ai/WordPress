<?php
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['g8_options'] = array();
$GLOBALS['g8_environment'] = 'staging';
$GLOBALS['g8_epoch'] = 1;
$GLOBALS['g8_after_slo_cas'] = null;
class WP_Error {
 private $code;
 public function __construct( $code, $message = '', $data = null ) { $this->code = $code; }
 public function get_error_code() { return $this->code; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function get_option( $key, $default = false ) { return array_key_exists( $key, $GLOBALS['g8_options'] ) ? $GLOBALS['g8_options'][ $key ] : $default; }
function add_option( $key, $value, $deprecated = '', $autoload = null ) {
 if ( array_key_exists( $key, $GLOBALS['g8_options'] ) ) return false;
 $GLOBALS['g8_options'][ $key ] = $value; return true;
}
function maybe_serialize( $v ) { return is_array( $v ) || is_object( $v ) ? serialize( $v ) : $v; }
function wp_cache_delete( $key, $group = '' ) { return true; }
function wp_salt( $type = 'auth' ) { return 'g8-ci-test-secret'; }
function current_user_can( $capability ) { return 'manage_options' === $capability; }
function get_current_user_id() { return 101; }
function add_action( $hook, $callback ) { return true; }
class G8_Test_DB {
 public $options = 'wp_options';
 public function prepare( $query, ...$args ) { return $args; }
 public function query( $args ) {
  list( $next, $key, $expected ) = $args;
  if ( ! array_key_exists( $key, $GLOBALS['g8_options'] ) || maybe_serialize( $GLOBALS['g8_options'][ $key ] ) !== $expected ) return 0;
  $GLOBALS['g8_options'][ $key ] = unserialize( $next );
  if ( 'mad4b_scp_g8_automation_slo_v1' === $key && is_callable( $GLOBALS['g8_after_slo_cas'] ) ) {
   $callback = $GLOBALS['g8_after_slo_cas']; $GLOBALS['g8_after_slo_cas'] = null; $callback();
  }
  return 1;
 }
}
$wpdb = new G8_Test_DB();
class MAD4B_SCP_Site_Profile {
 public static function profile_digest() { return hash( 'sha256', 'g8-test-profile' ); }
 public static function configured() { return true; }
 public static function current_environment() { return $GLOBALS['g8_environment']; }
 public static function origin_enrolled() { return true; }
 public static function site_urls_match_enrollment() { return true; }
 public static function managed_runtime_enabled() { return true; }
}
class MAD4B_SCP_Restore_Epoch {
 public static function status( $initialize = false, $refresh = false ) {
  return array( 'ready' => true, 'epoch' => $GLOBALS['g8_epoch'], 'site_uuid' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', 'external_record_sha256' => str_repeat( 'a', 64 ) );
 }
}
class MAD4B_SCP_Live_Acceptance_Observer {
 public static function build_provenance_identity_status() {
  return array( 'identity_ready' => true, 'source_commit_sha' => str_repeat( 'b', 40 ), 'build_fingerprint' => str_repeat( 'c', 64 ),
   'package_manifest_digest' => str_repeat( 'd', 64 ), 'artifact_identity' => 'g8-ci-artifact' );
 }
}
class MAD4B_SCP_Runtime_Convergence {
 public static $ready = false;
 public static function status() { return array( 'required_blockers' => self::$ready ? array() : array( 'repair_pending' ) ); }
}
require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-g8-record.php';
require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-automation-slo.php';
function g8_check( $assertion, $reason ) { if ( ! $assertion ) { fwrite( STDERR, 'FAIL: ' . $reason . PHP_EOL ); exit( 1 ); } }
function g8_is_error( $result, $code ) { return is_wp_error( $result ) && $code === $result->get_error_code(); }
// HMAC validation must reject objects without invoking their serialization
// callbacks. This is separate from an invalid MAC or a malformed scalar.
class G8_Test_Untrusted_Serialize {
	public function __serialize() {
		$GLOBALS['g8_untrusted_record_serialize_invoked'] = true;
		return array( 'side_effect' => true );
	}
}
$malformed_record = array( 'contract' => 'mad4b.test.v1', 'authorizing' => false,
	'payload' => array( 'innocent' => new G8_Test_Untrusted_Serialize() ),
	'seal' => str_repeat( '0', 64 ) );
g8_check( false === MAD4B_SCP_G8_Record::valid( $malformed_record, 'mad4b.test.v1' )
	&& empty( $GLOBALS['g8_untrusted_record_serialize_invoked'] ),
	'stored record HMAC validation must reject an object without invoking __serialize' );
g8_check( '' === MAD4B_SCP_G8_Record::digest( $malformed_record )
	&& '' === MAD4B_SCP_G8_Record::seal( $malformed_record )
	&& empty( $GLOBALS['g8_untrusted_record_serialize_invoked'] ),
	'shared digest and signing helpers must reject the same malicious object without executing it' );

$provider = 'runtime-convergence'; $capability = 'safe-phases'; $generation = str_repeat( 'e', 64 );
$switch = MAD4B_SCP_Automation_SLO::switch_status();
g8_check( $switch['integrity_valid'] && ! empty( $switch['scopes']['*'] ), 'default must pause' );
g8_check( 'automation_kill_switch' === MAD4B_SCP_Automation_SLO::admission( $provider, $capability )['reason'], 'default auto admission must deny' );
g8_check( true === MAD4B_SCP_Automation_SLO::change_switch( '*', false, 0 ), 'explicit admin resume' );
g8_check( 'within_budget' === MAD4B_SCP_Automation_SLO::admission( $provider, $capability )['reason'], 'resumed admission' );
g8_check( g8_is_error( MAD4B_SCP_Automation_SLO::change_switch( '*', true, 0 ), 'mad4b_automation_switch_revision_conflict' ), 'stale switch revision' );
$ticket = MAD4B_SCP_Automation_SLO::reserve( $provider, $capability, $generation );
g8_check( is_array( $ticket ) && false === $ticket['pre_ready'], 'initial ticket prestate' );
g8_check( g8_is_error( MAD4B_SCP_Automation_SLO::reserve( $provider, $capability, $generation ), 'mad4b_automation_capability_queue_busy' ), 'concurrent ticket rejected' );
MAD4B_SCP_Runtime_Convergence::$ready = true;
$completed = array(
 'contract' => 'mad4b.runtime-convergence-apply.v1',
 'state' => 'completed',
 'changed_safe_phases' => array( 'schema' ),
 'readback' => array( 'required_blockers' => array() ),
 'checkpoint' => array( 'state' => 'completed', 'last_execution_source' => 'post_update_cron',
  'changed_safe_phases' => array( 'schema' ), 'target_identity' => array( 'exact_test' => $generation ),
  'g8_current_slice_changed_safe_phases' => array( 'schema' ) ),
);
$completed['checkpoint']['g8_local_causal_receipt'] = MAD4B_SCP_Automation_SLO::local_causal_receipt( $ticket, $completed['checkpoint'] );
g8_check( is_array( $completed['checkpoint']['g8_local_causal_receipt'] ), 'issue bounded local Cron receipt' );
$GLOBALS['g8_options']['mad4b_scp_runtime_convergence_v1'] = $completed['checkpoint'];
g8_check( true === MAD4B_SCP_Automation_SLO::finish_existing( $ticket, $completed ), 'finish success' );
g8_check( g8_is_error( MAD4B_SCP_Automation_SLO::finish_existing( $ticket, $completed ), 'mad4b_automation_ticket_stale' ), 'replay rejected' );
$status = MAD4B_SCP_Automation_SLO::status();
g8_check( 1 === $status['outcomes']['verified_repair'], 'measured repair count' );
g8_check( 1 === $status['recent_outcome_receipt_count']
	&& 1 === count( $status['recent_outcome_receipts'] )
	&& 'verified_repair' === $status['recent_outcome_receipts'][0]['outcome']
	&& $status['recent_outcome_receipts'][0]['local_causal_receipt_sha256']
		=== $completed['checkpoint']['g8_local_causal_receipt']['seal'],
	'first verified repair has a durable ticket-correlated local receipt' );
$recorded = $GLOBALS['g8_options'][ MAD4B_SCP_Automation_SLO::OPTION ];
$forged_chain = $recorded;
$forged_chain['outcome_receipts'][0]['outcome'] = 'failed';
$forged_chain['seal'] = MAD4B_SCP_G8_Record::seal( $forged_chain );
$GLOBALS['g8_options'][ MAD4B_SCP_Automation_SLO::OPTION ] = $forged_chain;
g8_check( 'mad4b_automation_metrics_lost' === MAD4B_SCP_Automation_SLO::admission( $provider, $capability )['reason'],
	'a re-sealed but altered event chain cannot pass integrity validation' );
$GLOBALS['g8_options'][ MAD4B_SCP_Automation_SLO::OPTION ] = $recorded;
$forged_completed = $completed;
$forged_completed['checkpoint']['g8_current_slice_changed_safe_phases'] = array( 'provider' );
$unknown = MAD4B_SCP_Automation_SLO::reserve( $provider, 'unrelated-test', $generation );
g8_check( is_array( $unknown ), 'test forged independent receipt with unrelated ticket' );
g8_check( true === MAD4B_SCP_Automation_SLO::finish_existing( $unknown, $forged_completed ),
 'unbound proof produces a handoff, not a verified repair' );
$healthy = MAD4B_SCP_Automation_SLO::reserve( $provider, $capability, $generation );
g8_check( is_array( $healthy ) && true === $healthy['pre_ready'], 'healthy prestate' );
g8_check( true === MAD4B_SCP_Automation_SLO::finish_existing( $healthy, $completed ), 'already ready finish' );
$status = MAD4B_SCP_Automation_SLO::status();
g8_check( 1 === $status['outcomes']['verified_repair'] && 2 === $status['outcomes']['handoff'], 'no false repair count' );
$GLOBALS['g8_after_slo_cas'] = static function () {
 $revision = MAD4B_SCP_Automation_SLO::switch_status()['revision'];
 g8_check( true === MAD4B_SCP_Automation_SLO::change_switch( '*', false, $revision ), 'switch ABA revision advance' );
};
$race = MAD4B_SCP_Automation_SLO::reserve( $provider, $capability, $generation );
g8_check( g8_is_error( $race, 'mad4b_automation_switch_raced' ), 'switch ABA must cancel' );
$status = MAD4B_SCP_Automation_SLO::status();
g8_check( 0 === $status['pending_count'] && 1 === $status['outcomes']['cancelled'], 'raced ticket consumed' );
// A pause or resume invalidates an already-reserved ticket before its next mutation boundary.
$midflight = MAD4B_SCP_Automation_SLO::reserve( $provider, 'other-phase', $generation );
g8_check( is_array( $midflight ) && true === MAD4B_SCP_Automation_SLO::ticket_allowed( $midflight ), 'live ticket boundary' );
$revision = MAD4B_SCP_Automation_SLO::switch_status()['revision'];
g8_check( true === MAD4B_SCP_Automation_SLO::change_switch( '*', false, $revision ), 'inflight revision advance' );
g8_check( g8_is_error( MAD4B_SCP_Automation_SLO::ticket_allowed( $midflight ), 'mad4b_automation_switch_raced' ), 'midflight ticket must stop after any switch change' );
g8_check( true === MAD4B_SCP_Automation_SLO::finish_existing( $midflight, new WP_Error( 'paused' ) ), 'interrupted outcome cleanup' );
$revision = MAD4B_SCP_Automation_SLO::switch_status()['revision'];
g8_check( true === MAD4B_SCP_Automation_SLO::change_switch( '*', true, $revision ), 'operator pause' );
g8_check( 'automation_kill_switch' === MAD4B_SCP_Automation_SLO::admission( $provider, $capability )['reason'], 'operator pause enforced' );
$option = MAD4B_SCP_Automation_SLO::SWITCH_OPTION;
$valid = $GLOBALS['g8_options'][ $option ];
$corrupt = $valid; $corrupt['scopes'] = array( 'runtime-convergence:*' => 'not-a-boolean' );
$corrupt['seal'] = MAD4B_SCP_G8_Record::seal( $corrupt );
$GLOBALS['g8_options'][ $option ] = $corrupt;
g8_check( ! MAD4B_SCP_Automation_SLO::switch_status()['integrity_valid'], 'signed invalid switch scope rejected' );
g8_check( 'kill_switch_integrity_lost' === MAD4B_SCP_Automation_SLO::admission( $provider, $capability )['reason'], 'invalid switch fail closed' );
$GLOBALS['g8_options'][ $option ] = $valid;
$future_switch = $valid; $future_switch['updated_at'] = time() + 3600;
$future_switch['seal'] = MAD4B_SCP_G8_Record::seal( $future_switch );
$GLOBALS['g8_options'][ $option ] = $future_switch;
g8_check( ! MAD4B_SCP_Automation_SLO::switch_status()['integrity_valid'],
	'future-dated signed switch cannot resume automatic work after a clock rollback' );
$GLOBALS['g8_options'][ $option ] = $valid;
$GLOBALS['g8_epoch'] = 2;
g8_check( 'kill_switch_integrity_lost' === MAD4B_SCP_Automation_SLO::admission( $provider, $capability )['reason'], 'restore epoch drift fail closed' );
$GLOBALS['g8_epoch'] = 1; $GLOBALS['g8_environment'] = 'production';
g8_check( 'enrolled_staging_required' === MAD4B_SCP_Automation_SLO::admission( $provider, $capability )['reason'], 'Production fail closed' );
g8_check( ! MAD4B_SCP_Automation_SLO::admission( $provider, $capability, 5 )['allowed'], 'L5 denied' );
g8_check( MAD4B_SCP_Automation_SLO::admission( $provider, $capability, 1 )['allowed'], 'L1 observation preserved' );
echo 'G8_AUTOMATION_SLO_CONTRACT: PASS' . PHP_EOL;
