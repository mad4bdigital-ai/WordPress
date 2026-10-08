<?php
/** Actual worker regression: bounded slices, signatures, races and provider-local isolation. */
define( 'ABSPATH', __DIR__ . '/' ); define( 'MAD4B_SCP_VERSION', 'fixture-94' );
$GLOBALS['options'] = array(); $GLOBALS['scheduled'] = 0; $GLOBALS['writes'] = 0;
function absint( $value ) { return abs( (int) $value ); }
function is_admin() { return false; }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function get_option( $key, $default = false ) { return $GLOBALS['options'][ $key ] ?? $default; }
function update_option( $key, $value, $autoload = null ) { ++$GLOBALS['writes']; $GLOBALS['options'][ $key ] = $value; return true; }
function wp_json_encode( $value ) { return json_encode( $value ); }
function wp_salt( $scheme ) { return 'test-secret'; }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( $value ) ); }
function wp_generate_uuid4() { return bin2hex( random_bytes( 16 ) ); }
function wp_next_scheduled( $hook ) { return $GLOBALS['scheduled']; }
function wp_unschedule_event( ...$args ) { $GLOBALS['scheduled'] = 0; }
function wp_schedule_single_event( $when, $hook ) { $GLOBALS['scheduled'] = $when; return true; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
class WP_Error { private $code; public function __construct( $code = 'fixture_error', $message = '' ) { $this->code = $code; } public function get_error_code() { return $this->code; } }
class MAD4B_SCP_Site_Profile {
 static $environment = 'staging'; static $digest = 'profile1';
 static function configured() { return true; } static function current_environment() { return self::$environment; }
 static function origin_enrolled() { return true; } static function site_urls_match_enrollment() { return true; }
 static function managed_runtime_enabled() { return true; } static function profile_digest() { return self::$digest; }
 static function skills_enabled() { return true; }
}
class MAD4B_SCP_Runtime_Maintenance_Lease {
 static $busy = false; static $lost = false; static $releases = 0;
 static function acquire( $owner ) { return self::$busy ? new WP_Error() : 'token'; }
 static function refresh( ...$args ) { return self::$lost ? new WP_Error() : true; } static function release( ...$args ) { ++self::$releases; }
}
// This legacy worker fixture isolates its existing lease/provenance contract.
// The separate g8-adaptive-automatic-handoffs.php exercises the real SLO.
class MAD4B_SCP_Automation_SLO {
 static function reserve( $provider, $capability, $generation ) {
  return array( 'provider' => $provider, 'capability' => $capability, 'generation' => $generation );
 }
 static function ticket_allowed( $ticket ) { return true; }
 static function finish_existing( $ticket, $result ) { return true; }
}
class MAD4B_SCP_Runtime_Convergence {
 const CHECKPOINT_OPTION = 'fixture_core_checkpoint'; static $calls = 0;
 static function mark_activation_pending() {
  ++self::$calls;
  return array( 'scheduled' => true, 'state' => 'pending_safe_phases', 'target_identity' => array(
   'source_commit_sha' => str_repeat( 'd', 40 ), 'build_fingerprint' => str_repeat( 'a', 64 ),
   'package_manifest_digest' => str_repeat( 'e', 64 ), 'artifact_identity' => 'mad4b-site-control-plane-fixture',
  ) );
 }
}
class MAD4B_SCP_Staging_Write_Authority {
 static $stored_bound = false; static $match = false;
 static function candidate_binding_status() {
  return array(
   'required' => true,
   'stored_bound' => self::$stored_bound,
   'match' => self::$match,
   'current_source_commit_sha' => str_repeat( 'a', 40 ),
   'current_build_fingerprint' => str_repeat( 'b', 64 ),
   'current_package_manifest_digest' => str_repeat( 'c', 64 ),
   'current_artifact_identity' => 'mad4b-site-control-plane-fixture',
  );
 }
}
class MAD4B_SCP_Schema { static function is_ready() { return true; } }
class MAD4B_SCP_Skill_Provider_Discovery {
 static $calls = 0; static $state = 'ready'; static $inspect_ready = true; static $conflicts = array();
 static function reconcile() {
  ++self::$calls;
  return array( 'state' => self::$state, 'current_request_observed' => true, 'skipped_conflict' => self::$conflicts );
 }
 static function inspect() { return array( 'state' => self::$inspect_ready ? 'ready' : 'drifted', 'ready' => self::$inspect_ready ); }
}
class MAD4B_SCP_Live_Acceptance_Observer {
 static $calls = 0; static $race = false; static $event_race = false; static $valid = true; static $throw = false; static $drift_at_call = 0; static $identity_incomplete = false; static $artifact_missing = false;
 static function build_provenance_status() {
  ++self::$calls;
  if ( self::$drift_at_call === self::$calls ) MAD4B_SCP_Site_Profile::$digest = 'raced-profile';
  if ( self::$throw ) { MAD4B_SCP_Adaptive_Runtime_Convergence::enqueue(); throw new RuntimeException( 'PRIVATE worker path' ); }
  if ( self::$race && 0 === self::$calls % 2 ) MAD4B_SCP_Site_Profile::$digest = 'raced-profile';
  if ( self::$event_race && 0 === self::$calls % 2 ) MAD4B_SCP_Adaptive_Runtime_Convergence::enqueue();
  return array( 'runtime_manifest_match' => self::$valid, 'source_commit_sha' => self::$identity_incomplete ? '' : str_repeat( 'd', 40 ), 'build_fingerprint' => str_repeat( 'a', 64 ), 'package_manifest_digest' => str_repeat( 'e', 64 ), 'artifact_identity' => self::$artifact_missing ? '' : 'mad4b-site-control-plane-fixture' );
 }
}
class MAD4B_SCP_Provider_Contracts { static function all() { return array_fill_keys( array( 'alpha', 'beta', 'gamma', 'delta', 'epsilon' ), array() ); } }
class MAD4B_SCP_Provider_Compatibility_Certification {
 static $calls = array(); static $version = '5.1.0'; static $throw = ''; static $invalid = ''; static $malformed = false; static $verified = false;
 static function supports_provider( $provider ) { return true; } static function clear_request_cache() {}
 static function capability_certification( $input ) {
  $id = $input['provider_id']; self::$calls[] = $id;
  if ( self::$throw === $id ) throw new RuntimeException( 'PRIVATE ERROR PATH' );
  if ( self::$invalid === $id ) return new WP_Error();
  $base = array( 'risk' => 'read', 'structural_compatible' => true, 'read_eligible' => true, 'surface_exposed' => true, 'capability_contract_digest' => str_repeat( 'b', 64 ) );
  return array( 'contract' => 'mad4b.provider-capability-certification-result.v1', 'provider_id' => $id, 'artifact' => array( 'runtime_artifact_fingerprint' => hash( 'sha256', self::$version . $id ), 'installed_version' => self::$version ), 'capabilities' => array(
   'read' => $base,
   'write' => array_merge( $base, array( 'risk' => 'bounded_write', 'read_eligible' => false, 'write_eligible' => self::$verified, 'reversible' => true, 'behavioral_evidence' => array( 'behavioral_verified' => self::$verified, 'rollback_verified' => self::$verified ) ) ),
   'broken' => array_merge( $base, array( 'structural_compatible' => false, 'read_eligible' => false ) ),
   'malformed' => self::$malformed ? 'invalid' : array_merge( $base, array( 'read_eligible' => false ) ),
  ) );
 }
}
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-auto-reconcile-scenarios.php';
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-adaptive-runtime-convergence.php';
function check( $ok, $why ) { if ( ! $ok ) throw new RuntimeException( $why ); }
$probe_method = new ReflectionMethod( 'MAD4B_SCP_Adaptive_Runtime_Convergence', 'core_probe_scheduled' );
$probe_method->setAccessible( true );
$installed = array( 'source_commit_sha' => str_repeat( 'd', 40 ), 'build_fingerprint' => str_repeat( 'a', 64 ),
 'package_manifest_digest' => str_repeat( 'e', 64 ), 'artifact_identity' => 'mad4b-site-control-plane-fixture' );
foreach ( array(
 array( 'scheduled' => false, 'state' => 'pending_manual_resume', 'target_identity' => $installed ),
 array( 'scheduled' => false, 'state' => 'self_update_checkpoint_preserved', 'target_identity' => $installed ),
 array( 'scheduled' => true, 'state' => 'observe_only_non_staging', 'target_identity' => $installed ),
 array( 'state' => 'pending_safe_phases', 'target_identity' => $installed ),
 array( 'scheduled' => 'true', 'state' => 'pending_safe_phases', 'target_identity' => $installed ),
 array( 'scheduled' => true, 'state' => 'pending_safe_phases' ),
 array( 'scheduled' => true, 'state' => 'pending_safe_phases', 'target_identity' => array_merge( $installed, array( 'build_fingerprint' => str_repeat( 'f', 64 ) ) ) ),
 array( 'scheduled' => true, 'state' => 'self_update_checkpoint_preserved', 'target_identity' => array_merge( $installed, array( 'artifact_identity' => 'other-install' ) ) ),
 false,
) as $unconfirmed ) {
 check( false === $probe_method->invoke( null, $unconfirmed, $installed ), 'Unscheduled or foreign core target was falsely accepted' );
}
check( true === $probe_method->invoke( null, array( 'scheduled' => true, 'state' => 'pending_safe_phases', 'target_identity' => $installed ), $installed ), 'Exact core scheduler receipt rejected' );
check( true === $probe_method->invoke( null, array( 'scheduled' => true, 'state' => 'self_update_checkpoint_preserved', 'target_identity' => $installed ), $installed ), 'Exact preserved self-update receipt rejected' );
MAD4B_SCP_Adaptive_Runtime_Convergence::enqueue();
MAD4B_SCP_Adaptive_Runtime_Convergence::observe();
$first = MAD4B_SCP_Adaptive_Runtime_Convergence::status( array( 'include_capabilities' => true ) );
check( 'OBSERVING' === $first['state'] && 3 === count( $first['providers'] ), 'Worker exceeded bounded provider slice' );
MAD4B_SCP_Adaptive_Runtime_Convergence::observe();
$ready = MAD4B_SCP_Adaptive_Runtime_Convergence::status( array( 'include_capabilities' => true ) );
check( 'OBSERVED' === $ready['state'] && 5 === count( $ready['providers'] ) && $ready['receipt_integrity_valid'], 'Resumed worker lost or fabricated registry evidence' );
check( 'READ_COMPATIBLE' === $ready['providers']['alpha']['capabilities']['read']['state'], 'Version drift disabled compatible reads' );
check( 'CANARY_REQUIRED' === $ready['providers']['alpha']['capabilities']['write']['state'], 'Unknown artifact gained write eligibility without behavior' );
check( 'GOVERNED_CANARY_REQUIRED' === $ready['providers']['alpha']['capabilities']['write']['remediation']['lane'], 'Canary was misclassified as zero-manual auto repair' );
check( 'NO_OP' === $ready['providers']['alpha']['capabilities']['read']['remediation']['lane'], 'Verified compatible read was not classified as no-op' );
check( 1 === $ready['providers']['alpha']['remediation_lane_counts']['GOVERNED_CANARY_REQUIRED'], 'Per-provider operator lane counts lost high-risk separation' );
check( 'ISOLATED' === $ready['providers']['alpha']['capabilities']['broken']['state'], 'Broken capability was not isolated' );
check( ! $ready['authorizing'] && ! $ready['production_mutation'], 'Observation claimed authority' );
check( 1 === MAD4B_SCP_Runtime_Convergence::$calls && 1 === MAD4B_SCP_Skill_Provider_Discovery::$calls, 'Core or Managed Skills convergence repeated across slices' );
$core_registry = $GLOBALS['options'][MAD4B_SCP_Adaptive_Runtime_Convergence::OPTION];
check( ! empty( $core_registry['core_enqueued_generation'] ), 'Core convergence generation marker was not persisted across slices' );
$event_before_probe = $GLOBALS['options'][MAD4B_SCP_Adaptive_Runtime_Convergence::EVENT_OPTION];
MAD4B_SCP_Staging_Write_Authority::$stored_bound = true;
MAD4B_SCP_Staging_Write_Authority::$match = false;
$GLOBALS['options'][MAD4B_SCP_Adaptive_Runtime_Convergence::FALLBACK_PROBE_OPTION] = 0;
$scheduled_before_probe = $GLOBALS['scheduled'];
MAD4B_SCP_Adaptive_Runtime_Convergence::maybe_schedule();
$fallback_event = $GLOBALS['options'][MAD4B_SCP_Adaptive_Runtime_Convergence::EVENT_OPTION];
check( $event_before_probe['event_id'] !== $fallback_event['event_id'] && 'candidate_binding_probe' === $fallback_event['source'], 'Candidate-binding fallback probe did not wake the observer' );
$GLOBALS['options'][MAD4B_SCP_Adaptive_Runtime_Convergence::EVENT_OPTION] = $event_before_probe;
$GLOBALS['scheduled'] = $scheduled_before_probe;
MAD4B_SCP_Staging_Write_Authority::$stored_bound = false;
MAD4B_SCP_Staging_Write_Authority::$match = false;
// Read pages are deterministic and cannot combine two signed observations.
$saved_registry = $GLOBALS['options'][MAD4B_SCP_Adaptive_Runtime_Convergence::OPTION];
$large_registry = $saved_registry;
for ( $i = 0; $i < 15; ++$i ) $large_registry['providers']['synthetic-' . sprintf( '%02d', $i )] = $large_registry['providers']['alpha'];
$seal_registry = new ReflectionMethod( 'MAD4B_SCP_Adaptive_Runtime_Convergence', 'seal' ); $seal_registry->setAccessible( true );
$large_registry['seal'] = $seal_registry->invoke( null, $large_registry ); $GLOBALS['options'][MAD4B_SCP_Adaptive_Runtime_Convergence::OPTION] = $large_registry;
$writes_before_page = $GLOBALS['writes'];
$page = MAD4B_SCP_Adaptive_Runtime_Convergence::status();
check( 8 === count( $page['providers'] ) && $page['page']['has_more'] && 20 === $page['page']['total_provider_count'], 'Default response was not provider-bounded' );
check( ! isset( $page['providers']['alpha']['capabilities'] ) && 4 === $page['providers']['alpha']['capability_count'], 'Summary leaked full details or lost capability counts' );
$seen = array_keys( $page['providers'] );
while ( $page['page']['has_more'] ) {
 $page = MAD4B_SCP_Adaptive_Runtime_Convergence::status( array( 'after_provider' => $page['page']['next_after_provider'], 'expected_receipt_sha256' => $page['page']['receipt_sha256'] ) );
 check( is_array( $page ), 'Stable signed page could not be read' );
 $seen = array_merge( $seen, array_keys( $page['providers'] ) );
}
check( 20 === count( $seen ) && 20 === count( array_unique( $seen ) ), 'Provider paging skipped or repeated a row' );
$stale_page = MAD4B_SCP_Adaptive_Runtime_Convergence::status( array( 'after_provider' => 'alpha', 'expected_receipt_sha256' => str_repeat( '0', 64 ) ) );
check( is_wp_error( $stale_page ), 'A page accepted a foreign observation fingerprint' );
check( $writes_before_page === $GLOBALS['writes'], 'Bounded read pages mutated runtime evidence' );
foreach ( array(
 array( 'provider_id' => array( 'alpha' ) ),
 array( 'limit' => '100' ),
 array( 'after_provider' => array( 'alpha' ) ),
 array( 'include_capabilities' => 'true' ),
 array( 'provider_id' => 'alpha', 'unexpected' => 1 ),
 array( 'expected_receipt_sha256' => array( '0' ) ),
) as $invalid_selector ) {
 $denied = MAD4B_SCP_Adaptive_Runtime_Convergence::status( $invalid_selector );
 check( is_wp_error( $denied ) && 'mad4b_adaptive_runtime_input_invalid' === $denied->get_error_code(), 'Malformed selector was normalized rather than rejected' );
}
check( $writes_before_page === $GLOBALS['writes'], 'Malformed selectors unexpectedly performed writes' );
$old_receipt = $saved_registry;
$old_receipt['observed_at'] = gmdate( 'c', time() - MAD4B_SCP_Adaptive_Runtime_Convergence::MAX_OBSERVATION_AGE - 5 );
$old_receipt['seal'] = $seal_registry->invoke( null, $old_receipt );
$GLOBALS['options'][MAD4B_SCP_Adaptive_Runtime_Convergence::OPTION] = $old_receipt;
$old_status = MAD4B_SCP_Adaptive_Runtime_Convergence::status( array( 'provider_id' => 'alpha', 'include_capabilities' => true ) );
check( $old_status['receipt_integrity_valid'] && ! $old_status['observation_current'] && ! $old_status['observation_fresh']
    && 'STALE_OBSERVATION' === $old_status['providers']['alpha']['capabilities']['read']['state'], 'Expired signed observation appeared current' );
$future_receipt = $saved_registry;
$future_receipt['observed_at'] = gmdate( 'c', time() + MAD4B_SCP_Adaptive_Runtime_Convergence::MAX_CLOCK_SKEW + 120 );
$future_receipt['seal'] = $seal_registry->invoke( null, $future_receipt );
$GLOBALS['options'][MAD4B_SCP_Adaptive_Runtime_Convergence::OPTION] = $future_receipt;
$future_status = MAD4B_SCP_Adaptive_Runtime_Convergence::status( array( 'provider_id' => 'alpha', 'include_capabilities' => true ) );
check( ! $future_status['observation_fresh'] && ! $future_status['observation_current'], 'Future-dated signed observation appeared current' );
$GLOBALS['options'][MAD4B_SCP_Adaptive_Runtime_Convergence::OPTION] = $saved_registry;
MAD4B_SCP_Adaptive_Runtime_Convergence::observe();
$partial = MAD4B_SCP_Adaptive_Runtime_Convergence::status( array( 'include_capabilities' => true ) );
check( 'STALE_OBSERVATION' === $partial['providers']['gamma']['capabilities']['read']['state'] && ! $partial['providers']['gamma']['observation_current'], 'A provider awaiting the current slice was reported as freshly compatible' );
check( $partial['providers']['alpha']['observation_current'], 'Current provider slice was hidden as stale' );
MAD4B_SCP_Adaptive_Runtime_Convergence::observe();
check( 1 === MAD4B_SCP_Runtime_Convergence::$calls && 1 === MAD4B_SCP_Skill_Provider_Discovery::$calls, 'Unchanged graph triggered repeated convergence' );
$before = $GLOBALS['writes']; MAD4B_SCP_Adaptive_Runtime_Convergence::status( array( 'include_capabilities' => true ) ); check( $before === $GLOBALS['writes'], 'Read status performed a write' );
MAD4B_SCP_Provider_Compatibility_Certification::$version = '8.99.123'; MAD4B_SCP_Provider_Compatibility_Certification::$verified = true;
MAD4B_SCP_Adaptive_Runtime_Convergence::enqueue();
MAD4B_SCP_Adaptive_Runtime_Convergence::observe(); MAD4B_SCP_Adaptive_Runtime_Convergence::observe();
$fresh = MAD4B_SCP_Adaptive_Runtime_Convergence::status( array( 'include_capabilities' => true ) );
check( 'ACTIVE' === $fresh['providers']['alpha']['capabilities']['write']['state'], 'Verified bounded capability required a static version allowlist' );
check( 'contract_unchanged' === $fresh['providers']['alpha']['capability_diff']['write'], 'Artifact identity confused with contract compatibility' );
check( $ready['providers']['alpha']['artifact_fingerprint'] !== $fresh['providers']['alpha']['artifact_fingerprint'], 'New artifact did not invalidate identity' );
check( 2 === MAD4B_SCP_Skill_Provider_Discovery::$calls, 'Changed provider graph did not reconcile Managed Skills' );
// Simulate artifact/profile drift after provider scans but immediately before
// managed-file reconciliation: the automatic worker must not touch managed
// skill files or publish an observation under a stale site generation.
MAD4B_SCP_Provider_Compatibility_Certification::$version = '8.99.124';
MAD4B_SCP_Adaptive_Runtime_Convergence::enqueue();
MAD4B_SCP_Adaptive_Runtime_Convergence::observe(); // First slice cannot reconcile providers yet.
$prior_registry = get_option( MAD4B_SCP_Adaptive_Runtime_Convergence::OPTION );
$prior_skills = MAD4B_SCP_Skill_Provider_Discovery::$calls;
MAD4B_SCP_Live_Acceptance_Observer::$drift_at_call = MAD4B_SCP_Live_Acceptance_Observer::$calls + 2;
MAD4B_SCP_Adaptive_Runtime_Convergence::observe(); // Second slice reaches the skill side-effect fence.
check( $prior_skills === MAD4B_SCP_Skill_Provider_Discovery::$calls, 'Stale generation reconciled managed skill files' );
check( $prior_registry === get_option( MAD4B_SCP_Adaptive_Runtime_Convergence::OPTION ), 'Stale generation published a new observation' );
MAD4B_SCP_Live_Acceptance_Observer::$drift_at_call = 0;
MAD4B_SCP_Site_Profile::$digest = 'profile1';
MAD4B_SCP_Provider_Compatibility_Certification::$version = '8.99.123';

MAD4B_SCP_Provider_Compatibility_Certification::$throw = 'alpha'; MAD4B_SCP_Adaptive_Runtime_Convergence::enqueue();
MAD4B_SCP_Adaptive_Runtime_Convergence::observe(); MAD4B_SCP_Adaptive_Runtime_Convergence::observe();
$isolated = MAD4B_SCP_Adaptive_Runtime_Convergence::status( array( 'include_capabilities' => true ) );
check( 'ISOLATED' === $isolated['providers']['alpha']['state'] && 'READ_COMPATIBLE' === $isolated['providers']['beta']['capabilities']['read']['state'], 'Provider exception poisoned neighboring capabilities' );
check( false === strpos( json_encode( $isolated ), 'PRIVATE' ), 'Raw provider error leaked' );
MAD4B_SCP_Provider_Compatibility_Certification::$throw = ''; MAD4B_SCP_Provider_Compatibility_Certification::$invalid = 'alpha'; MAD4B_SCP_Provider_Compatibility_Certification::$malformed = true;
MAD4B_SCP_Adaptive_Runtime_Convergence::enqueue(); MAD4B_SCP_Adaptive_Runtime_Convergence::observe(); MAD4B_SCP_Adaptive_Runtime_Convergence::observe();
$invalid = MAD4B_SCP_Adaptive_Runtime_Convergence::status( array( 'include_capabilities' => true ) );
check( 'ISOLATED' === $invalid['providers']['alpha']['state'] && 'READ_COMPATIBLE' === $invalid['providers']['beta']['capabilities']['read']['state'], 'Invalid provider result poisoned a neighboring provider' );
check( 'ISOLATED' === $invalid['providers']['beta']['capabilities']['malformed']['state'], 'Malformed capability row broke the observer' );
MAD4B_SCP_Provider_Compatibility_Certification::$invalid = ''; MAD4B_SCP_Provider_Compatibility_Certification::$malformed = false;
$GLOBALS['options'][MAD4B_SCP_Adaptive_Runtime_Convergence::OPTION]['providers']['beta']['capabilities']['read']['state'] = 'FAKED';
check( 'NOT_OBSERVED' === MAD4B_SCP_Adaptive_Runtime_Convergence::status( array( 'include_capabilities' => true ) )['state'], 'Tampered receipt was accepted' );
MAD4B_SCP_Provider_Compatibility_Certification::$throw = ''; MAD4B_SCP_Live_Acceptance_Observer::$race = true;
$before = get_option( MAD4B_SCP_Adaptive_Runtime_Convergence::OPTION );
MAD4B_SCP_Adaptive_Runtime_Convergence::observe();
check( $before === get_option( MAD4B_SCP_Adaptive_Runtime_Convergence::OPTION ), 'Profile race published stale certification' );
MAD4B_SCP_Live_Acceptance_Observer::$race = false; MAD4B_SCP_Site_Profile::$digest = 'profile1';
MAD4B_SCP_Live_Acceptance_Observer::$event_race = true; MAD4B_SCP_Live_Acceptance_Observer::$calls = 0;
MAD4B_SCP_Adaptive_Runtime_Convergence::observe();
check( $before === get_option( MAD4B_SCP_Adaptive_Runtime_Convergence::OPTION ), 'New update event published an older provider generation' );
MAD4B_SCP_Live_Acceptance_Observer::$event_race = false;
MAD4B_SCP_Live_Acceptance_Observer::$valid = false; $GLOBALS['scheduled'] = 0;
for ( $i = 0; $i < 6; ++$i ) { $GLOBALS['scheduled'] = 0; MAD4B_SCP_Adaptive_Runtime_Convergence::observe(); }
$failed = MAD4B_SCP_Adaptive_Runtime_Convergence::status( array( 'include_capabilities' => true ) )['last_worker_failure'];
check( 6 === $failed['attempts'] && 'EXTERNAL_ACTION_REQUIRED' === $failed['state'] && 0 === $GLOBALS['scheduled'], 'Manifest failure retried without a bound' );
$terminal_event = get_option( MAD4B_SCP_Adaptive_Runtime_Convergence::EVENT_OPTION );
$before_probe_writes = $GLOBALS['writes'];
MAD4B_SCP_Staging_Write_Authority::$stored_bound = true;
MAD4B_SCP_Staging_Write_Authority::$match = false;
$GLOBALS['options'][MAD4B_SCP_Adaptive_Runtime_Convergence::FALLBACK_PROBE_OPTION] = 0;
MAD4B_SCP_Adaptive_Runtime_Convergence::maybe_schedule();
check( $terminal_event === get_option( MAD4B_SCP_Adaptive_Runtime_Convergence::EVENT_OPTION )
    && $before_probe_writes === $GLOBALS['writes'], 'Recurring identical binding drift bypassed terminal worker policy' );
MAD4B_SCP_Staging_Write_Authority::$stored_bound = false;
MAD4B_SCP_Staging_Write_Authority::$match = false;
MAD4B_SCP_Live_Acceptance_Observer::$valid = true;
$terminal_writes = $GLOBALS['writes'];
$terminal_probes = MAD4B_SCP_Live_Acceptance_Observer::$calls;
MAD4B_SCP_Adaptive_Runtime_Convergence::observe(); MAD4B_SCP_Adaptive_Runtime_Convergence::observe();
check( $terminal_writes === $GLOBALS['writes'] && $terminal_probes === MAD4B_SCP_Live_Acceptance_Observer::$calls,
    'Old Cron bypassed terminal failure gate' );
check( 'EXTERNAL_ACTION_REQUIRED' === MAD4B_SCP_Adaptive_Runtime_Convergence::status()['last_worker_failure']['state'], 'Terminal decision vanished without a new event' );
MAD4B_SCP_Adaptive_Runtime_Convergence::enqueue( array( 'source' => 'wordpress_upgrader' ) );
MAD4B_SCP_Adaptive_Runtime_Convergence::observe(); MAD4B_SCP_Adaptive_Runtime_Convergence::observe();
check( array() === MAD4B_SCP_Adaptive_Runtime_Convergence::status( array( 'include_capabilities' => true ) )['last_worker_failure'], 'New lifecycle event could not restart observation' );
MAD4B_SCP_Runtime_Maintenance_Lease::$busy = true; $GLOBALS['scheduled'] = 0; $before_writes = $GLOBALS['writes'];
MAD4B_SCP_Adaptive_Runtime_Convergence::observe();
check( $before_writes === $GLOBALS['writes'] && $GLOBALS['scheduled'] > time(), 'Busy maintenance lease mutated or abandoned observation' );
MAD4B_SCP_Runtime_Maintenance_Lease::$busy = false; MAD4B_SCP_Runtime_Maintenance_Lease::$lost = true; $GLOBALS['scheduled'] = 0;
MAD4B_SCP_Adaptive_Runtime_Convergence::observe();
check( $before_writes === $GLOBALS['writes'] && $GLOBALS['scheduled'] > time(), 'Lost maintenance fence published or abandoned observation' );
MAD4B_SCP_Runtime_Maintenance_Lease::$lost = false; MAD4B_SCP_Live_Acceptance_Observer::$throw = true;
MAD4B_SCP_Adaptive_Runtime_Convergence::observe();
check( array() === MAD4B_SCP_Adaptive_Runtime_Convergence::status( array( 'include_capabilities' => true ) )['last_worker_failure'], 'Failed old worker poisoned a newer event' );
MAD4B_SCP_Live_Acceptance_Observer::$throw = false;
$GLOBALS['options'][MAD4B_SCP_Adaptive_Runtime_Convergence::EVENT_OPTION] = 'corrupt';
$before_writes = $GLOBALS['writes']; MAD4B_SCP_Adaptive_Runtime_Convergence::status( array( 'include_capabilities' => true ) );
check( $before_writes === $GLOBALS['writes'], 'Corrupted event was repaired by a passive status read' );
MAD4B_SCP_Adaptive_Runtime_Convergence::observe(); MAD4B_SCP_Adaptive_Runtime_Convergence::observe();
check( 'OBSERVED' === MAD4B_SCP_Adaptive_Runtime_Convergence::status( array( 'include_capabilities' => true ) )['state'], 'Worker did not recover a malformed event without poisoning neighbors' );
// A non-throwing provider skills failure must never become RECONCILED.
// The next attempt must remain eligible even if the fabric hash is unchanged.
MAD4B_SCP_Skill_Provider_Discovery::$state = 'seed_pack_not_ready';
MAD4B_SCP_Provider_Compatibility_Certification::$version = '8.99.130';
MAD4B_SCP_Adaptive_Runtime_Convergence::enqueue();
MAD4B_SCP_Adaptive_Runtime_Convergence::observe(); MAD4B_SCP_Adaptive_Runtime_Convergence::observe();
$failed_skills = MAD4B_SCP_Adaptive_Runtime_Convergence::status();
check( 'RECONCILIATION_REQUIRED' === $failed_skills['managed_skills']['state']
    && 'seed_pack_not_ready' === $failed_skills['managed_skills']['reason'], 'Non-throwing seed-pack failure was misreported as reconciled' );
MAD4B_SCP_Skill_Provider_Discovery::$state = 'ready';
MAD4B_SCP_Skill_Provider_Discovery::$inspect_ready = false;
MAD4B_SCP_Adaptive_Runtime_Convergence::observe(); MAD4B_SCP_Adaptive_Runtime_Convergence::observe();
$bad_readback = MAD4B_SCP_Adaptive_Runtime_Convergence::status();
check( 'RECONCILIATION_REQUIRED' === $bad_readback['managed_skills']['state']
    && 'skill_mapping_readback_incomplete' === $bad_readback['managed_skills']['reason'], 'Unverified skill files were treated as current' );
MAD4B_SCP_Skill_Provider_Discovery::$inspect_ready = true;
MAD4B_SCP_Skill_Provider_Discovery::$conflicts = array( 'alpha:owner_changed' );
MAD4B_SCP_Adaptive_Runtime_Convergence::observe(); MAD4B_SCP_Adaptive_Runtime_Convergence::observe();
$conflicted = MAD4B_SCP_Adaptive_Runtime_Convergence::status();
check( 'RECONCILIATION_REQUIRED' === $conflicted['managed_skills']['state']
    && 'skill_reconciliation_conflicts' === $conflicted['managed_skills']['reason'], 'Owner conflict falsely reported as ready' );
MAD4B_SCP_Skill_Provider_Discovery::$conflicts = array();
MAD4B_SCP_Adaptive_Runtime_Convergence::observe(); MAD4B_SCP_Adaptive_Runtime_Convergence::observe();
$ready_skills = MAD4B_SCP_Adaptive_Runtime_Convergence::status();
check( 'RECONCILED' === $ready_skills['managed_skills']['state'] && ! empty( $ready_skills['managed_skills']['readback_verified'] ), 'Recovered skills were not marked reconciled after exact readback' );
MAD4B_SCP_Provider_Compatibility_Certification::$version = '8.99.123';

// Missing exact provenance must enter the bounded worker failure policy and
// never invoke core reconciliation or managed-skill mutations.
$before_skills = MAD4B_SCP_Skill_Provider_Discovery::$calls;
$before_core = MAD4B_SCP_Runtime_Convergence::$calls;
MAD4B_SCP_Live_Acceptance_Observer::$identity_incomplete = true;
MAD4B_SCP_Adaptive_Runtime_Convergence::enqueue();
MAD4B_SCP_Adaptive_Runtime_Convergence::observe();
$incomplete_status = MAD4B_SCP_Adaptive_Runtime_Convergence::status();
check( $before_skills === MAD4B_SCP_Skill_Provider_Discovery::$calls && $before_core === MAD4B_SCP_Runtime_Convergence::$calls, 'Incomplete identity drove automatic mutation' );
check( 1 === $incomplete_status['last_worker_failure']['attempts'] && 'installed_package_manifest_unverified' === $incomplete_status['last_worker_failure']['code'], 'Incomplete identity ignored bounded retry policy' );
MAD4B_SCP_Live_Acceptance_Observer::$identity_incomplete = false;
MAD4B_SCP_Adaptive_Runtime_Convergence::enqueue();
MAD4B_SCP_Adaptive_Runtime_Convergence::observe(); MAD4B_SCP_Adaptive_Runtime_Convergence::observe();
check( 'OBSERVED' === MAD4B_SCP_Adaptive_Runtime_Convergence::status()['state'], 'Worker could not recover after exact identity returned' );
MAD4B_SCP_Live_Acceptance_Observer::$artifact_missing = true;
MAD4B_SCP_Adaptive_Runtime_Convergence::enqueue();
MAD4B_SCP_Adaptive_Runtime_Convergence::observe();
$missing_artifact = MAD4B_SCP_Adaptive_Runtime_Convergence::status();
check( 'installed_package_manifest_unverified' === $missing_artifact['last_worker_failure']['code'], 'Missing artifact identity did not fail closed' );
MAD4B_SCP_Live_Acceptance_Observer::$artifact_missing = false;
MAD4B_SCP_Adaptive_Runtime_Convergence::enqueue();
MAD4B_SCP_Adaptive_Runtime_Convergence::observe(); MAD4B_SCP_Adaptive_Runtime_Convergence::observe();
check( 'OBSERVED' === MAD4B_SCP_Adaptive_Runtime_Convergence::status()['state'], 'Artifact recovery did not restore observation' );

// Production must never suggest automatic observation based on an old
// signed Staging receipt. The read-only status surface remains accessible.
MAD4B_SCP_Site_Profile::$environment = 'production'; $before = $GLOBALS['writes'];
$prod_status = MAD4B_SCP_Adaptive_Runtime_Convergence::status( array( 'provider_id' => 'alpha', 'include_capabilities' => true ) );
check( false === $prod_status['auto_observation_available'] && 'NOT_ELIGIBLE' === $prod_status['scheduler_state'], 'Production incorrectly advertised automatic observation' );
check( ! $prod_status['providers']['alpha']['observation_current']
    && 'REVIEW_REQUIRED' === $prod_status['providers']['alpha']['capabilities']['read']['remediation']['lane']
    && ! $prod_status['providers']['alpha']['capabilities']['read']['remediation']['worker_may_observe'], 'Production stale evidence was routed into an automatic lane' );
MAD4B_SCP_Adaptive_Runtime_Convergence::enqueue(); MAD4B_SCP_Adaptive_Runtime_Convergence::observe();
check( $before === $GLOBALS['writes'], 'Production observation mutated state' );
check( 'HIGH_RISK_GATED' === MAD4B_SCP_Adaptive_Runtime_Convergence::reduce_capability( array( 'risk' => 'high_risk_write', 'structural_compatible' => true ) )['state'], 'High-risk capability auto-promoted' );
check( 'EXTERNAL_ACTION_REQUIRED' === MAD4B_SCP_Adaptive_Runtime_Convergence::reduce_capability( array( 'risk' => 'bounded_write', 'structural_compatible' => true, 'artifact_authority_required' => true ) )['state'], 'New artifact authority was not gated' );
// A disabled WP-Cron scheduler cannot promise hands-off observation even in
// Staging. No read should repair or mutate the current persisted receipt.
MAD4B_SCP_Site_Profile::$environment = 'staging';
define( 'DISABLE_WP_CRON', true );
$writes_before_disabled_status = $GLOBALS['writes'];
MAD4B_SCP_Adaptive_Runtime_Convergence::enqueue();
$disabled_status = MAD4B_SCP_Adaptive_Runtime_Convergence::status( array( 'provider_id' => 'alpha', 'include_capabilities' => true ) );
check( false === $disabled_status['auto_observation_available'] && 'EXTERNAL_ACTION_REQUIRED' === $disabled_status['scheduler_state'], 'Disabled WP-Cron was treated as an active automatic scheduler' );
check( 'REVIEW_REQUIRED' === $disabled_status['providers']['alpha']['capabilities']['read']['remediation']['lane']
    && ! $disabled_status['providers']['alpha']['capabilities']['read']['remediation']['worker_may_observe'], 'Disabled scheduler offered false automatic repair' );
check( $writes_before_disabled_status + 1 === $GLOBALS['writes'], 'Passive disabled-Cron status read persisted changes' );
$corrupt = get_option( MAD4B_SCP_Adaptive_Runtime_Convergence::EVENT_OPTION );
$corrupt['failure_code'] = array( 'invalid' );
$corrupt['failure_state'] = array( 'RETRY_PENDING' );
$corrupt['attempts'] = array( 999 );
$GLOBALS['options'][MAD4B_SCP_Adaptive_Runtime_Convergence::EVENT_OPTION] = $corrupt;
$before_corrupt_writes = $GLOBALS['writes'];
$before_corrupt_probes = MAD4B_SCP_Live_Acceptance_Observer::$calls;
MAD4B_SCP_Adaptive_Runtime_Convergence::observe();
$corrupt_status = MAD4B_SCP_Adaptive_Runtime_Convergence::status();
check( $before_corrupt_writes === $GLOBALS['writes'] && $before_corrupt_probes === MAD4B_SCP_Live_Acceptance_Observer::$calls,
    'Malformed failure state triggered an automatic repair' );
check( 'REVIEW_REQUIRED' === $corrupt_status['last_worker_failure']['state']
    && 'invalid_failure_record' === $corrupt_status['last_worker_failure']['code']
    && 0 === $corrupt_status['last_worker_failure']['attempts']
    && ! $corrupt_status['auto_observation_available'], 'Malformed persisted event was not safely represented' );
echo "Adaptive runtime convergence runtime: PASS\n";
