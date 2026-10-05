<?php
/** Actual worker regression: bounded slices, signatures, races and provider-local isolation. */
define( 'ABSPATH', __DIR__ . '/' ); define( 'MAD4B_SCP_VERSION', 'fixture-94' );
$GLOBALS['options'] = array(); $GLOBALS['scheduled'] = 0; $GLOBALS['writes'] = 0;
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
class WP_Error { public function get_error_code() { return 'fixture_error'; } }
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
class MAD4B_SCP_Runtime_Convergence {
 const CHECKPOINT_OPTION = 'fixture_core_checkpoint'; static $calls = 0;
 static function mark_activation_pending() { ++self::$calls; return array( 'state' => 'pending' ); }
}
class MAD4B_SCP_Schema { static function is_ready() { return true; } }
class MAD4B_SCP_Skill_Provider_Discovery { static $calls = 0; static function reconcile() { ++self::$calls; return true; } }
class MAD4B_SCP_Live_Acceptance_Observer {
 static $calls = 0; static $race = false; static $event_race = false; static $valid = true; static $throw = false;
 static function build_provenance_status() {
  ++self::$calls;
  if ( self::$throw ) { MAD4B_SCP_Adaptive_Runtime_Convergence::enqueue(); throw new RuntimeException( 'PRIVATE worker path' ); }
  if ( self::$race && 0 === self::$calls % 2 ) MAD4B_SCP_Site_Profile::$digest = 'raced-profile';
  if ( self::$event_race && 0 === self::$calls % 2 ) MAD4B_SCP_Adaptive_Runtime_Convergence::enqueue();
  return array( 'runtime_manifest_match' => self::$valid, 'build_fingerprint' => str_repeat( 'a', 64 ) );
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
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-adaptive-runtime-convergence.php';
function check( $ok, $why ) { if ( ! $ok ) throw new RuntimeException( $why ); }
MAD4B_SCP_Adaptive_Runtime_Convergence::enqueue();
MAD4B_SCP_Adaptive_Runtime_Convergence::observe();
$first = MAD4B_SCP_Adaptive_Runtime_Convergence::status();
check( 'OBSERVING' === $first['state'] && 3 === count( $first['providers'] ), 'Worker exceeded bounded provider slice' );
MAD4B_SCP_Adaptive_Runtime_Convergence::observe();
$ready = MAD4B_SCP_Adaptive_Runtime_Convergence::status();
check( 'OBSERVED' === $ready['state'] && 5 === count( $ready['providers'] ) && $ready['receipt_integrity_valid'], 'Resumed worker lost or fabricated registry evidence' );
check( 'READ_COMPATIBLE' === $ready['providers']['alpha']['capabilities']['read']['state'], 'Version drift disabled compatible reads' );
check( 'CANARY_REQUIRED' === $ready['providers']['alpha']['capabilities']['write']['state'], 'Unknown artifact gained write eligibility without behavior' );
check( 'ISOLATED' === $ready['providers']['alpha']['capabilities']['broken']['state'], 'Broken capability was not isolated' );
check( ! $ready['authorizing'] && ! $ready['production_mutation'], 'Observation claimed authority' );
check( 1 === MAD4B_SCP_Runtime_Convergence::$calls && 1 === MAD4B_SCP_Skill_Provider_Discovery::$calls, 'Core or Managed Skills convergence repeated across slices' );
MAD4B_SCP_Adaptive_Runtime_Convergence::observe();
$partial = MAD4B_SCP_Adaptive_Runtime_Convergence::status();
check( 'STALE_OBSERVATION' === $partial['providers']['gamma']['capabilities']['read']['state'] && ! $partial['providers']['gamma']['observation_current'], 'A provider awaiting the current slice was reported as freshly compatible' );
check( $partial['providers']['alpha']['observation_current'], 'Current provider slice was hidden as stale' );
MAD4B_SCP_Adaptive_Runtime_Convergence::observe();
check( 1 === MAD4B_SCP_Runtime_Convergence::$calls && 1 === MAD4B_SCP_Skill_Provider_Discovery::$calls, 'Unchanged graph triggered repeated convergence' );
$before = $GLOBALS['writes']; MAD4B_SCP_Adaptive_Runtime_Convergence::status(); check( $before === $GLOBALS['writes'], 'Read status performed a write' );
MAD4B_SCP_Provider_Compatibility_Certification::$version = '8.99.123'; MAD4B_SCP_Provider_Compatibility_Certification::$verified = true;
MAD4B_SCP_Adaptive_Runtime_Convergence::enqueue();
MAD4B_SCP_Adaptive_Runtime_Convergence::observe(); MAD4B_SCP_Adaptive_Runtime_Convergence::observe();
$fresh = MAD4B_SCP_Adaptive_Runtime_Convergence::status();
check( 'ACTIVE' === $fresh['providers']['alpha']['capabilities']['write']['state'], 'Verified bounded capability required a static version allowlist' );
check( 'contract_unchanged' === $fresh['providers']['alpha']['capability_diff']['write'], 'Artifact identity confused with contract compatibility' );
check( $ready['providers']['alpha']['artifact_fingerprint'] !== $fresh['providers']['alpha']['artifact_fingerprint'], 'New artifact did not invalidate identity' );
check( 2 === MAD4B_SCP_Skill_Provider_Discovery::$calls, 'Changed provider graph did not reconcile Managed Skills' );
MAD4B_SCP_Provider_Compatibility_Certification::$throw = 'alpha'; MAD4B_SCP_Adaptive_Runtime_Convergence::enqueue();
MAD4B_SCP_Adaptive_Runtime_Convergence::observe(); MAD4B_SCP_Adaptive_Runtime_Convergence::observe();
$isolated = MAD4B_SCP_Adaptive_Runtime_Convergence::status();
check( 'ISOLATED' === $isolated['providers']['alpha']['state'] && 'READ_COMPATIBLE' === $isolated['providers']['beta']['capabilities']['read']['state'], 'Provider exception poisoned neighboring capabilities' );
check( false === strpos( json_encode( $isolated ), 'PRIVATE' ), 'Raw provider error leaked' );
MAD4B_SCP_Provider_Compatibility_Certification::$throw = ''; MAD4B_SCP_Provider_Compatibility_Certification::$invalid = 'alpha'; MAD4B_SCP_Provider_Compatibility_Certification::$malformed = true;
MAD4B_SCP_Adaptive_Runtime_Convergence::enqueue(); MAD4B_SCP_Adaptive_Runtime_Convergence::observe(); MAD4B_SCP_Adaptive_Runtime_Convergence::observe();
$invalid = MAD4B_SCP_Adaptive_Runtime_Convergence::status();
check( 'ISOLATED' === $invalid['providers']['alpha']['state'] && 'READ_COMPATIBLE' === $invalid['providers']['beta']['capabilities']['read']['state'], 'Invalid provider result poisoned a neighboring provider' );
check( 'ISOLATED' === $invalid['providers']['beta']['capabilities']['malformed']['state'], 'Malformed capability row broke the observer' );
MAD4B_SCP_Provider_Compatibility_Certification::$invalid = ''; MAD4B_SCP_Provider_Compatibility_Certification::$malformed = false;
$GLOBALS['options'][MAD4B_SCP_Adaptive_Runtime_Convergence::OPTION]['providers']['beta']['capabilities']['read']['state'] = 'FAKED';
check( 'NOT_OBSERVED' === MAD4B_SCP_Adaptive_Runtime_Convergence::status()['state'], 'Tampered receipt was accepted' );
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
$failed = MAD4B_SCP_Adaptive_Runtime_Convergence::status()['last_worker_failure'];
check( 6 === $failed['attempts'] && 'EXTERNAL_ACTION_REQUIRED' === $failed['state'] && 0 === $GLOBALS['scheduled'], 'Manifest failure retried without a bound' );
MAD4B_SCP_Live_Acceptance_Observer::$valid = true;
MAD4B_SCP_Adaptive_Runtime_Convergence::observe(); MAD4B_SCP_Adaptive_Runtime_Convergence::observe();
check( array() === MAD4B_SCP_Adaptive_Runtime_Convergence::status()['last_worker_failure'], 'Recovery retained a stale worker failure' );
MAD4B_SCP_Runtime_Maintenance_Lease::$busy = true; $GLOBALS['scheduled'] = 0; $before_writes = $GLOBALS['writes'];
MAD4B_SCP_Adaptive_Runtime_Convergence::observe();
check( $before_writes === $GLOBALS['writes'] && $GLOBALS['scheduled'] > time(), 'Busy maintenance lease mutated or abandoned observation' );
MAD4B_SCP_Runtime_Maintenance_Lease::$busy = false; MAD4B_SCP_Runtime_Maintenance_Lease::$lost = true; $GLOBALS['scheduled'] = 0;
MAD4B_SCP_Adaptive_Runtime_Convergence::observe();
check( $before_writes === $GLOBALS['writes'] && $GLOBALS['scheduled'] > time(), 'Lost maintenance fence published or abandoned observation' );
MAD4B_SCP_Runtime_Maintenance_Lease::$lost = false; MAD4B_SCP_Live_Acceptance_Observer::$throw = true;
MAD4B_SCP_Adaptive_Runtime_Convergence::observe();
check( array() === MAD4B_SCP_Adaptive_Runtime_Convergence::status()['last_worker_failure'], 'Failed old worker poisoned a newer event' );
MAD4B_SCP_Live_Acceptance_Observer::$throw = false;
$GLOBALS['options'][MAD4B_SCP_Adaptive_Runtime_Convergence::EVENT_OPTION] = 'corrupt';
$before_writes = $GLOBALS['writes']; MAD4B_SCP_Adaptive_Runtime_Convergence::status();
check( $before_writes === $GLOBALS['writes'], 'Corrupted event was repaired by a passive status read' );
MAD4B_SCP_Adaptive_Runtime_Convergence::observe(); MAD4B_SCP_Adaptive_Runtime_Convergence::observe();
check( 'OBSERVED' === MAD4B_SCP_Adaptive_Runtime_Convergence::status()['state'], 'Worker did not recover a malformed event without poisoning neighbors' );
MAD4B_SCP_Site_Profile::$environment = 'production'; $before = $GLOBALS['writes'];
MAD4B_SCP_Adaptive_Runtime_Convergence::enqueue(); MAD4B_SCP_Adaptive_Runtime_Convergence::observe();
check( $before === $GLOBALS['writes'], 'Production observation mutated state' );
check( 'HIGH_RISK_GATED' === MAD4B_SCP_Adaptive_Runtime_Convergence::reduce_capability( array( 'risk' => 'high_risk_write', 'structural_compatible' => true ) )['state'], 'High-risk capability auto-promoted' );
check( 'EXTERNAL_ACTION_REQUIRED' === MAD4B_SCP_Adaptive_Runtime_Convergence::reduce_capability( array( 'risk' => 'bounded_write', 'structural_compatible' => true, 'artifact_authority_required' => true ) )['state'], 'New artifact authority was not gated' );
echo "Adaptive runtime convergence runtime: PASS\n";
