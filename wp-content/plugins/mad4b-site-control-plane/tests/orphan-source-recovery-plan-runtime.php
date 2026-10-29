<?php
/* QA32C no-WordPress plan/registration harness; no disk, network or writes. */
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );
class WP_Error { public $code; public function __construct( $code ) { $this->code = $code; } }
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function wp_json_encode( $v ) { return json_encode( $v ); }
$GLOBALS['registered_abilities'] = array();
$GLOBALS['actions'] = array();
function add_action( $hook, $handler, $priority ) { $GLOBALS['actions'][] = array( $hook, $handler, $priority ); }
function wp_has_ability( $ability ) { return isset( $GLOBALS['registered_abilities'][ $ability ] ); }
function wp_register_ability( $ability, $definition ) { $GLOBALS['registered_abilities'][ $ability ] = $definition; }
class MAD4B_SCP_Policy { public static function can_read() { return true; } }
class MAD4B_SCP_Site_Profile {
    public static $status;
    public static function status() { return self::$status; }
}
class MAD4B_SCP_Self_Update {
    public static $result;
    public static function native_plan( $input ) { return self::$result; }
}
class MAD4B_SCP_Live_Acceptance_Observer {
    public static $result;
    public static function build_provenance_status() { return self::$result; }
}
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-orphan-source-recovery.php';
function qa32c_verify( $good, $label ) {
    if ( ! $good ) { fwrite( STDERR, 'FAIL: ' . $label . PHP_EOL ); exit( 1 ); }
}
$class = 'MAD4B_SCP_Orphan_Source_Recovery';
$class::boot();
qa32c_verify( count( $GLOBALS['actions'] ) === 1
    && 'wp_abilities_api_init' === $GLOBALS['actions'][0][0]
    && 35 === $GLOBALS['actions'][0][2], 'ability registered in normal lifecycle' );
$class::register_ability();
$ability = $GLOBALS['registered_abilities'][ $class::ABILITY ] ?? array();
qa32c_verify( ! empty( $ability ) && $ability['category'] === 'mad4b-read', 'read-only category' );
qa32c_verify( $ability['meta']['annotations']['destructive'] === false
    && $ability['meta']['annotations']['readonly'] === true
    && $ability['meta']['show_in_rest'] === false, 'closed tool metadata' );
$current = array(
    'source_commit_sha' => str_repeat( 'a', 40 ),
    'version' => '0.4.0-rc.104',
    'build_fingerprint' => str_repeat( 'b', 64 ),
    'package_manifest_digest' => str_repeat( 'c', 64 ),
);
MAD4B_SCP_Site_Profile::$status = array(
    'environment' => 'staging', 'wordpress_environment_explicit' => true,
    'authority_ready' => true, 'binding_state' => 'exact',
    'origin_match' => true, 'deployment_binding_match' => true,
    'site_uuid' => '49c562d1-8f2f-456f-b454-26816c6ba4cb',
    'canonical_origin' => 'https://staging.example.test',
);
MAD4B_SCP_Self_Update::$result = array(
    'current' => $current,
    'release_source_relationship' => 'installed_source_unresolvable',
    'plan_sha256' => str_repeat( '1', 64 ),
    'target' => array(
        'source_commit_sha' => str_repeat( 'd', 40 ),
        'version' => '0.4.0-rc.104',
        'archive_sha256' => str_repeat( 'e', 64 ),
        'build_fingerprint' => str_repeat( 'f', 64 ),
        'package_manifest_digest' => str_repeat( '0', 64 ),
        'release_verdict_success' => true,
        'release_root_trust_verified' => true,
        'published_from_master' => true,
    ),
);
MAD4B_SCP_Live_Acceptance_Observer::$result = array(
    'runtime_manifest_match' => true,
    'source_commit_sha' => $current['source_commit_sha'],
    'build_fingerprint' => $current['build_fingerprint'],
    'package_manifest_digest' => $current['package_manifest_digest'],
);
$plan = $class::plan( array( 'reason' => 'Read-only orphan source preflight' ) );
qa32c_verify( ! is_wp_error( $plan ), 'plan must be available for reviewed evidence' );
qa32c_verify( $plan['admission']['preflight_ready'] === true
    && $plan['admission']['execution_allowed'] === false
    && $plan['authorizing'] === false && $plan['mutation_performed'] === false, 'plan cannot authorize update' );
qa32c_verify( preg_match( '/^[a-f0-9]{64}$/D', $plan['plan_sha256'] ) === 1, 'bounded exact plan sha' );
qa32c_verify( $class::plan( array( 'reason' => 'Read-only orphan source preflight' ) )['plan_sha256']
    === $plan['plan_sha256'], 'deterministic same evidence' );
qa32c_verify( $class::plan( array( 'reason' => 'Different recovery reason' ) )['plan_sha256']
    !== $plan['plan_sha256'], 'reason bound to plan' );
MAD4B_SCP_Live_Acceptance_Observer::$result['build_fingerprint'] = str_repeat( '8', 64 );
$changed = $class::plan( array( 'reason' => 'Read-only orphan source preflight' ) );
qa32c_verify( $changed['admission']['preflight_ready'] === false
    && in_array( 'installed_package_integrity_unverified', $changed['admission']['blockers'], true ),
    'source fingerprint drift is denied' );
MAD4B_SCP_Site_Profile::$status['environment'] = 'production';
$prod = $class::plan( array( 'reason' => 'Read-only orphan source preflight' ) );
qa32c_verify( $prod['admission']['preflight_ready'] === false
    && in_array( 'exact_staging_required', $prod['admission']['blockers'], true ), 'production denied' );
qa32c_verify( is_wp_error( $class::plan( array( 'reason' => 'x' ) ) ), 'short input denied' );
echo 'QA32C_ORPHAN_SOURCE_RECOVERY_PLAN: PASS 11 checks' . PHP_EOL;
