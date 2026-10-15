<?php
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );
define( 'MAD4B_SCP_VERSION', 'fixture-g8' );
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
 $GLOBALS['g8_options'][ $key ] = $value;
 if ( 'mad4b_scp_g8_automation_slo_v1' === $key && is_callable( $GLOBALS['g8_after_slo_cas'] ) ) {
  $callback = $GLOBALS['g8_after_slo_cas']; $GLOBALS['g8_after_slo_cas'] = null; $callback();
 }
 return true;
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
 public static function skills_enabled() { return true; }
}
class MAD4B_SCP_Restore_Epoch {
 public static function status( $initialize = false, $refresh = false ) {
  return array( 'ready' => true, 'epoch' => $GLOBALS['g8_epoch'], 'site_uuid' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', 'external_record_sha256' => str_repeat( 'a', 64 ) );
 }
}
class MAD4B_SCP_Live_Acceptance_Observer {
 public static function build_provenance_status() {
  return array_merge( self::build_provenance_identity_status(), array( 'runtime_manifest_match' => true ) );
 }
 public static function build_provenance_identity_status() {
  return array( 'identity_ready' => true, 'source_commit_sha' => str_repeat( 'b', 40 ), 'build_fingerprint' => str_repeat( 'c', 64 ),
   'package_manifest_digest' => str_repeat( 'd', 64 ), 'artifact_identity' => 'g8-ci-artifact' );
 }
}
class MAD4B_SCP_Runtime_Convergence {
 const CHECKPOINT_OPTION = 'mad4b_scp_runtime_convergence_v1';
 public static $ready = false; public static $calls = 0;
 public static function mark_activation_pending() {
  ++self::$calls;
  if ( is_callable( $GLOBALS['g8_core_effect_hook'] ?? null ) ) {
   $callback = $GLOBALS['g8_core_effect_hook']; $GLOBALS['g8_core_effect_hook'] = null; $callback();
  }
  $identity = MAD4B_SCP_Live_Acceptance_Observer::build_provenance_identity_status();
  unset( $identity['identity_ready'] ); $identity['version'] = MAD4B_SCP_VERSION;
  return array( 'scheduled' => true, 'state' => 'pending_safe_phases', 'target_identity' => $identity );
 }
 public static function status() { return array( 'required_blockers' => self::$ready ? array() : array( 'repair_pending' ) ); }
}

$GLOBALS['g8_scheduled'] = 0; $GLOBALS['g8_writes'] = 0;
function update_option( $key, $value, $autoload = null ) {
 ++$GLOBALS['g8_writes']; $GLOBALS['g8_options'][ $key ] = $value; return true;
}
function absint( $value ) { return abs( (int) $value ); }
function is_admin() { return false; }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( $value ) ); }
function wp_json_encode( $value ) { return json_encode( $value ); }
function wp_generate_uuid4() { return bin2hex( random_bytes( 16 ) ); }
function wp_next_scheduled( $hook ) { return $GLOBALS['g8_scheduled']; }
function wp_unschedule_event( ...$args ) { $GLOBALS['g8_scheduled'] = 0; }
function wp_schedule_single_event( $when, $hook ) { $GLOBALS['g8_scheduled'] = $when; return true; }
class MAD4B_SCP_Runtime_Maintenance_Lease {
 public static function acquire( $owner ) { return 'g8-maintenance-token'; }
 public static function refresh( ...$args ) { return true; }
 public static function release( ...$args ) {}
}
class MAD4B_SCP_Schema { public static function is_ready() { return true; } }
class MAD4B_SCP_Post_Update_Continuation {
 public static $calls = 0;
 public static function status() { return array( 'active' => false ); }
 public static function capture_ready_baseline( $lock, $owner = '' ) {
  ++self::$calls; return array( 'state' => 'observed', 'authorizing' => false );
 }
}
class MAD4B_SCP_Skill_Provider_Discovery {
 public static $calls = 0;
 public static function reconcile() {
  ++self::$calls;
  return array( 'state' => 'ready', 'current_request_observed' => true, 'skipped_conflict' => array() );
 }
 public static function inspect() { return array( 'state' => 'ready', 'ready' => true ); }
}
class MAD4B_SCP_Provider_Contracts {
 public static function all() { return array( 'alpha' => array() ); }
}
class MAD4B_SCP_Provider_Compatibility_Certification {
 public static $calls = 0;
 public static function supports_provider( $provider ) { return true; }
 public static function clear_request_cache() {}
 public static function capability_certification( $input ) {
  ++self::$calls;
  if ( is_callable( $GLOBALS['g8_assessment_hook'] ?? null ) ) {
   $callback = $GLOBALS['g8_assessment_hook']; $GLOBALS['g8_assessment_hook'] = null; $callback();
  }
  return array( 'contract' => 'mad4b.provider-capability-certification-result.v1',
   'provider_id' => $input['provider_id'],
   'artifact' => array( 'runtime_artifact_fingerprint' => str_repeat( 'f', 64 ), 'installed_version' => 'fixture' ),
   'capabilities' => array( 'read' => array( 'risk' => 'read', 'structural_compatible' => true,
    'read_eligible' => true, 'surface_exposed' => true, 'capability_contract_digest' => str_repeat( 'a', 64 ) ) ) );
 }
}
require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-g8-record.php';
require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-automation-slo.php';
require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-auto-reconcile-scenarios.php';
require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-adaptive-runtime-convergence.php';
function g8_handoff_assert( $okay, $reason ) {
 if ( ! $okay ) { fwrite( STDERR, 'FAIL: ' . $reason . PHP_EOL ); exit( 1 ); }
}
function g8_handoff_reset( $resume = true, $scope = '' ) {
 $GLOBALS['g8_options'] = array(); $GLOBALS['g8_environment'] = 'staging';
 $GLOBALS['g8_epoch'] = 1; $GLOBALS['g8_after_slo_cas'] = null;
 $GLOBALS['g8_core_effect_hook'] = null; $GLOBALS['g8_assessment_hook'] = null;
 $GLOBALS['g8_scheduled'] = 0;
 MAD4B_SCP_Runtime_Convergence::$calls = 0;
 MAD4B_SCP_Skill_Provider_Discovery::$calls = 0;
 MAD4B_SCP_Post_Update_Continuation::$calls = 0;
 MAD4B_SCP_Provider_Compatibility_Certification::$calls = 0;
 if ( $resume ) g8_handoff_assert( true === MAD4B_SCP_Automation_SLO::change_switch( '*', false, 0 ), 'fixture resumes exact global switch' );
 if ( '' !== $scope ) g8_handoff_pause( $scope );
 MAD4B_SCP_Adaptive_Runtime_Convergence::enqueue();
}
function g8_handoff_pause( $scope ) {
 $revision = MAD4B_SCP_Automation_SLO::switch_status()['revision'];
 g8_handoff_assert( true === MAD4B_SCP_Automation_SLO::change_switch( $scope, true, $revision ), 'exact scoped pause persists' );
}
function g8_handoff_run( $core, $skills, $baseline, $why ) {
 MAD4B_SCP_Adaptive_Runtime_Convergence::observe();
 g8_handoff_assert( $core === MAD4B_SCP_Runtime_Convergence::$calls
  && $skills === MAD4B_SCP_Skill_Provider_Discovery::$calls
  && $baseline === MAD4B_SCP_Post_Update_Continuation::$calls, $why );
 $view = MAD4B_SCP_Adaptive_Runtime_Convergence::status( array( 'include_capabilities' => true ) );
 g8_handoff_assert( 'OBSERVED' === $view['state'] && true === $view['receipt_integrity_valid']
  && false === $view['authorizing']
  && 'READ_COMPATIBLE' === $view['providers']['alpha']['capabilities']['read']['state'],
  'automatic denial preserves signed passive compatible reads' );
 $writes = $GLOBALS['g8_writes'];
 MAD4B_SCP_Adaptive_Runtime_Convergence::status();
 g8_handoff_assert( $writes === $GLOBALS['g8_writes'], 'read status remains mutation-free' );
 g8_handoff_assert( 0 === MAD4B_SCP_Automation_SLO::status()['pending_count'], 'every admitted handoff settles exactly once' );
}
g8_handoff_reset( false );
g8_handoff_run( 0, 0, 0, 'default global pause denies all real automatic handoffs' );
g8_handoff_reset();
g8_handoff_run( 1, 1, 1, 'unpaused existing handoffs pass without adding authority' );
$scheduled_core = MAD4B_SCP_Adaptive_Runtime_Convergence::status()['core_convergence'];
g8_handoff_assert( 'SCHEDULED' === $scheduled_core['state'] && true === $scheduled_core['scheduled'],
 'real worker accepts only a scheduled exact-target core handoff' );
g8_handoff_assert( 3 === MAD4B_SCP_Automation_SLO::status()['eligible_workload_count'],
 'all three existing automatic handoffs have durable admission evidence' );
foreach ( array(
 array( '*', 0, 0, 0 ),
 array( 'runtime-convergence:*', 0, 1, 0 ),
 array( 'runtime-convergence:enqueue', 0, 1, 1 ),
 array( 'runtime-convergence:authority-baseline', 1, 1, 0 ),
 array( 'managed-skills:*', 1, 0, 1 ),
 array( 'managed-skills:reconcile', 1, 0, 1 ),
) as $case ) {
 g8_handoff_reset( true, $case[0] );
 g8_handoff_run( $case[1], $case[2], $case[3], 'real automatic scope must obey ' . $case[0] );
}
g8_handoff_reset();
$GLOBALS['g8_after_slo_cas'] = static function () { g8_handoff_pause( '*' ); };
g8_handoff_run( 0, 0, 0, 'pause after first ticket reservation denies every side effect' );
g8_handoff_reset();
$GLOBALS['g8_assessment_hook'] = static function () { g8_handoff_pause( 'managed-skills:*' ); };
g8_handoff_run( 1, 0, 1, 'pause during passive provider scan denies the next skill file mutation' );
g8_handoff_reset();
$GLOBALS['g8_core_effect_hook'] = static function () { g8_handoff_pause( '*' ); };
g8_handoff_run( 1, 0, 0, 'pause during a running core call denies every subsequent effect' );
$metrics = MAD4B_SCP_Automation_SLO::status();
g8_handoff_assert( 1 === ( $metrics['outcomes']['failed'] ?? 0 )
 && 0 === ( $metrics['outcomes']['verified_repair'] ?? 0 ),
 'in-flight pause cannot become a verified repair receipt' );
g8_handoff_reset();
$ticket = MAD4B_SCP_Automation_SLO::reserve( 'runtime-convergence', 'safe-phases', str_repeat( 'e', 64 ) );
g8_handoff_assert( is_array( $ticket ), 'generic worker receives an ordinary ticket' );
g8_handoff_pause( 'managed-skills:*' );
$denied = MAD4B_SCP_Automation_SLO::additional_scope_allowed( $ticket, 'managed-skills', 'reconcile' );
g8_handoff_assert( is_wp_error( $denied ), 'mid-flight scope change rejects a generic worker Skills phase' );
MAD4B_SCP_Automation_SLO::finish_existing( $ticket, $denied );
$ticket = MAD4B_SCP_Automation_SLO::reserve( 'runtime-convergence', 'safe-phases', str_repeat( 'e', 64 ) );
g8_handoff_assert( is_array( $ticket ), 'new generic worker is not itself globally paused' );
foreach ( array( 'reconcile', 'certify' ) as $phase ) {
 $denied = MAD4B_SCP_Automation_SLO::additional_scope_allowed( $ticket, 'managed-skills', $phase );
 g8_handoff_assert( is_wp_error( $denied ) && 'mad4b_automation_kill_switch' === $denied->get_error_code(),
  'provider pause applies within generic ticket to ' . $phase );
}
MAD4B_SCP_Automation_SLO::finish_existing( $ticket, $denied );
g8_handoff_reset();
$GLOBALS['g8_assessment_hook'] = static function () {
 $event = $GLOBALS['g8_options'][ MAD4B_SCP_Adaptive_Runtime_Convergence::EVENT_OPTION ];
 $event['failure_state'] = 'REVIEW_REQUIRED'; $event['failure_code'] = 'operator_review_required';
 $GLOBALS['g8_options'][ MAD4B_SCP_Adaptive_Runtime_Convergence::EVENT_OPTION ] = $event;
};
MAD4B_SCP_Adaptive_Runtime_Convergence::observe();
g8_handoff_assert( 1 === MAD4B_SCP_Runtime_Convergence::$calls
 && 0 === MAD4B_SCP_Skill_Provider_Discovery::$calls && 0 === MAD4B_SCP_Post_Update_Continuation::$calls
 && 'REVIEW_REQUIRED' === $GLOBALS['g8_options'][ MAD4B_SCP_Adaptive_Runtime_Convergence::EVENT_OPTION ]['failure_state']
 && 0 === MAD4B_SCP_Automation_SLO::status()['pending_count'],
 'a mid-flight terminal lifecycle decision revokes later effects even with the same event id' );
class G8_Unsafe_Lifecycle_Event {
 public function __serialize() { $GLOBALS['g8_event_object_serialized'] = true; return array( 'effect' => true ); }
}
g8_handoff_reset();
$unsafe_event = $GLOBALS['g8_options'][ MAD4B_SCP_Adaptive_Runtime_Convergence::EVENT_OPTION ];
$unsafe_event['event_id'] = new G8_Unsafe_Lifecycle_Event();
$GLOBALS['g8_options'][ MAD4B_SCP_Adaptive_Runtime_Convergence::EVENT_OPTION ] = $unsafe_event;
$writes_before_unsafe_event = $GLOBALS['g8_writes'];
MAD4B_SCP_Adaptive_Runtime_Convergence::observe();
g8_handoff_assert( empty( $GLOBALS['g8_event_object_serialized'] )
 && 0 === MAD4B_SCP_Runtime_Convergence::$calls && 0 === MAD4B_SCP_Skill_Provider_Discovery::$calls
 && 0 === MAD4B_SCP_Post_Update_Continuation::$calls && $writes_before_unsafe_event === $GLOBALS['g8_writes'],
 'an executable lifecycle event is rejected before ticket hashing or any rewrite/effect' );
$unsafe_status = MAD4B_SCP_Adaptive_Runtime_Convergence::status();
g8_handoff_assert( false === $unsafe_status['auto_observation_available']
 && 'BLOCKED_BY_WORKER_POLICY' === $unsafe_status['scheduler_state']
 && empty( $GLOBALS['g8_event_object_serialized'] ), 'passive operator status must not advertise an executable event as schedulable' );
g8_handoff_reset();
$GLOBALS['g8_assessment_hook'] = static function () {
 $GLOBALS['g8_options'][ MAD4B_SCP_Adaptive_Runtime_Convergence::EVENT_OPTION ]['unexpected'] = new G8_Unsafe_Lifecycle_Event();
};
MAD4B_SCP_Adaptive_Runtime_Convergence::observe();
g8_handoff_assert( 1 === MAD4B_SCP_Runtime_Convergence::$calls
 && 0 === MAD4B_SCP_Skill_Provider_Discovery::$calls && 0 === MAD4B_SCP_Post_Update_Continuation::$calls
 && empty( $GLOBALS['g8_event_object_serialized'] ) && 0 === MAD4B_SCP_Automation_SLO::status()['pending_count'],
 'a mid-flight executable event member revokes later effects without serialization' );
echo 'G8_ADAPTIVE_AUTOMATIC_HANDOFFS: PASS' . PHP_EOL;
