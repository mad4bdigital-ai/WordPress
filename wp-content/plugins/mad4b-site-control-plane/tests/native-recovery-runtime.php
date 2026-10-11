<?php
/* Pure fixture: isolated options, no real DB, Host, installation or ownership mutation. */
define( 'ABSPATH', __DIR__ );
class WP_Error {
    private $code;
    public function __construct( $code, $message = '' ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error( $x ) { return $x instanceof WP_Error; }
$GLOBALS['options'] = array( 'legacy_real_option' => array( 'protected' => true ) );
$GLOBALS['admin'] = true;
$GLOBALS['abilities'] = array();
function current_user_can( $name ) { return $GLOBALS['admin']; }
function get_current_user_id() { return 47; }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function wp_register_ability( $name, $spec ) { $GLOBALS['abilities'][ $name ] = $spec; }
function wp_has_ability( $name ) { return isset( $GLOBALS['abilities'][ $name ] ); }
function add_action( $name, $callback, $priority = 10, $args = 1 ) {}
function wp_schedule_single_event( $ts, $hook, $args = array() ) { return true; }
function wp_clear_scheduled_hook( $hook, $args = array() ) { return 0; }
function get_option( $name, $default = false ) {
    return array_key_exists( $name, $GLOBALS['options'] ) ? $GLOBALS['options'][ $name ] : $default;
}
function add_option( $name, $value, $deprecated = '', $autoload = 'yes' ) {
    if ( array_key_exists( $name, $GLOBALS['options'] ) || $autoload !== false ) return false;
    $GLOBALS['options'][ $name ] = $value; return true;
}
function delete_option( $name ) {
    if ( ! array_key_exists( $name, $GLOBALS['options'] ) ) return false;
    unset( $GLOBALS['options'][ $name ] ); return true;
}
class MAD4B_SCP_Policy {
    public static function can_read() { return true; }
    public static function can_mutate() { return true; }
}
class MAD4B_SCP_Site_Profile {
    public static $environment = 'staging';
    public static function status() {
        return array( 'configured_environment' => self::$environment,
            'wordpress_environment' => self::$environment, 'authority_ready' => true,
            'origin_match' => true, 'environment_match' => true,
            'site_uuid' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
            'profile_digest' => str_repeat( 'a', 64 ), 'revision' => 8,
            'canonical_origin' => 'https://staging.allroyalegypt.com' );
    }
    public static function user_is_enrolled( $user ) { return true; }
}
class MAD4B_SCP_OAuth_Resource_Bridge {
    const AUTHORITY_STEP_UP_SCOPE = 'mad4b:authority:step-up';
    public static function verified_bearer_active() { return true; }
    public static function verified_bearer_has_scope( $scope ) { return true; }
    public static function verified_bearer_client_is( $client ) { return true; }
}
class MAD4B_SCP_Local_OAuth_Server { const CHATGPT_CIMD_CLIENT_ID = 'mad4b-chatgpt'; }
class MAD4B_SCP_Authorization {
    public static function authorize_mutation( $name, $surface, $provider, $input ) { return true; }
}
class MAD4B_SCP_Audit {
    public static $fail = false;
    public static $calls = array();
    public static function record( $ability, $payload, $outcome ) {
        self::$calls[] = $ability;
        return self::$fail ? new WP_Error( 'audit_failed' ) : array( 'recorded' => true );
    }
}
class MAD4B_SCP_Context_Authority {
    public static $revision = '1';
    public static $mutations = 0;
    public static function legacy_owner_transfer_plan( $x ) {
        return array( 'plan_sha256' => hash( 'sha256', self::$revision ),
            'asset_count' => 12 );
    }
    public static function legacy_owner_transfer_evidence( $x ) {
        return array( 'plan_sha256' => hash( 'sha256', self::$revision ),
            'provider_proof_sha256' => hash( 'sha256', 'provider' ),
            'matched_file_count' => 12, 'folder_membership_verified' => true,
            'independent_owner_approval_required' => true );
    }
}
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-native-recovery.php';
function native_assert( $pass, $why ) {
    if ( ! $pass ) { fwrite( STDERR, 'FAIL: ' . $why . PHP_EOL ); exit( 1 ); }
}
MAD4B_SCP_Native_Recovery::register_abilities();
native_assert( count( $GLOBALS['abilities'] ) === 4, 'four separately permissioned native abilities' );
foreach ( $GLOBALS['abilities'] as $name => $spec ) {
    native_assert( $spec['input_schema']['additionalProperties'] === false,
        'reject caller command strings, arbitrary SQL and foreign plugin options' );
    native_assert( $spec['meta']['mcp']['public'] === false,
        'no public REST or unauthenticated MCP' );
    native_assert( in_array( $name, array( 'mad4b/native-recovery-plan',
        'mad4b/native-recovery-start', 'mad4b/native-recovery-readback',
        'mad4b/native-recovery-cleanup' ), true ), 'exact semantic allowlist' );
}
$plan = MAD4B_SCP_Native_Recovery::plan( array( 'mode' => 'probe', 'reason' => 'fixture scratch' ) );
native_assert( is_array( $plan ) && $plan['requires_host_runner'] === false
    && $plan['ownership_transferred'] === false && $plan['mariadb_crash_certified'] === false,
    'native mode is Host-free but not independent Host/DB certification' );
$wrong = MAD4B_SCP_Native_Recovery::start( array( 'mode' => 'probe',
    'reason' => 'fixture scratch', 'expected_plan_sha256' => $plan['plan_sha256'],
    'confirmation' => 'YES' ) );
native_assert( is_wp_error( $wrong ) && ! isset( $GLOBALS['options'][MAD4B_SCP_Native_Recovery::OPTION] ),
    'wrong approval never writes' );
$start = MAD4B_SCP_Native_Recovery::start( array( 'mode' => 'probe',
    'reason' => 'fixture scratch', 'expected_plan_sha256' => $plan['plan_sha256'],
    'confirmation' => MAD4B_SCP_Native_Recovery::PROBE_CONFIRM ) );
native_assert( is_array( $start ) && $start['host_required'] === false
    && $start['context_registry_modified'] === false
    && isset( $GLOBALS['options'][MAD4B_SCP_Native_Recovery::OPTION] ),
    'scratch created without Host, originals untouched' );
$second_start = MAD4B_SCP_Native_Recovery::start( array( 'mode' => 'probe',
    'reason' => 'fixture scratch', 'expected_plan_sha256' => $plan['plan_sha256'],
    'confirmation' => MAD4B_SCP_Native_Recovery::PROBE_CONFIRM ) );
native_assert( is_wp_error( $second_start ), 'concurrent scratch denied' );
$same_request = MAD4B_SCP_Native_Recovery::readback( array( 'job_id' => $start['job_id'] ) );
native_assert( $same_request['state'] === 'WP_NATIVE_INCOMPLETE' &&
    $same_request['independent_request_observed'] === false,
    'same-request readback is not a cross-request pass' );
$writer = str_repeat( 'a', 32 ); $reader = str_repeat( 'b', 32 );
native_assert( MAD4B_SCP_Native_Recovery::cross_request_verified(
    $writer, $reader, 100, 95, true ), 'pure reducer requires separate request' );
native_assert( !MAD4B_SCP_Native_Recovery::cross_request_verified(
    $writer, $writer, 100, 95, true ), 'same request rejected' );
native_assert( !MAD4B_SCP_Native_Recovery::cross_request_verified(
    $writer, $reader, 100, 101, true ), 'expired observation rejected' );
native_assert( !MAD4B_SCP_Native_Recovery::cross_request_verified(
    $writer, $reader, 100, 95, false ), 'stale original shadow plan rejected' );
$GLOBALS['admin'] = false;
native_assert( is_wp_error( MAD4B_SCP_Native_Recovery::cleanup(
    array( 'job_id' => $start['job_id'], 'confirmation' => MAD4B_SCP_Native_Recovery::CLEAN_CONFIRM ) ) ),
    'non-owner denied scratch cleanup' );
$GLOBALS['admin'] = true;
$clean = MAD4B_SCP_Native_Recovery::cleanup( array(
    'job_id' => $start['job_id'], 'confirmation' => MAD4B_SCP_Native_Recovery::CLEAN_CONFIRM ) );
native_assert( is_array( $clean ) && $clean['scratch_removed']
    && ! isset( $GLOBALS['options'][MAD4B_SCP_Native_Recovery::OPTION] )
    && $GLOBALS['options']['legacy_real_option']['protected'] === true,
    'cleanup deletes scratch only; original options unchanged' );
$source = str_repeat( 'c', 64 );
$shadow = MAD4B_SCP_Native_Recovery::plan( array(
    'mode' => 'shadow', 'reason' => 'verified original source', 'source_id' => $source ) );
native_assert( is_array( $shadow ) && $shadow['asset_count'] === 12
    && $shadow['ownership_transferred'] === false, 'shadow plan uses original evidence without transfer' );
$begin = MAD4B_SCP_Native_Recovery::start( array(
    'mode' => 'shadow', 'reason' => 'verified original source',
    'source_id' => $source, 'expected_plan_sha256' => $shadow['plan_sha256'],
    'confirmation' => MAD4B_SCP_Native_Recovery::SHADOW_CONFIRM ) );
native_assert( is_array( $begin ) && $begin['ownership_transferred'] === false,
    'staged shadow remains non-activating' );
MAD4B_SCP_Context_Authority::$revision = '2';
$drift = MAD4B_SCP_Native_Recovery::readback( array( 'job_id' => $begin['job_id'] ) );
native_assert( $drift['state'] === 'WP_NATIVE_INCOMPLETE' &&
    !$drift['shadow_plan_still_exact'], 'legacy Context revision drift fails closed' );
$clean = MAD4B_SCP_Native_Recovery::cleanup( array(
    'job_id' => $begin['job_id'], 'confirmation' => MAD4B_SCP_Native_Recovery::CLEAN_CONFIRM ) );
native_assert( is_array( $clean ) && $clean['scratch_removed'], 'stale shadow can be cleaned' );
MAD4B_SCP_Site_Profile::$environment = 'production';
native_assert( is_wp_error( MAD4B_SCP_Native_Recovery::plan( array(
    'mode' => 'probe', 'reason' => 'deny production' ) ) ), 'never touch Production' );
native_assert( MAD4B_SCP_Context_Authority::$mutations === 0,
    'original ownership registry never called for mutation' );
native_assert( count( MAD4B_SCP_Audit::$calls ) >= 4,
    'native start and cleanup must use append-only MAD4B audit' );
echo "mad4b.site-control-plane.native-recovery.runtime.v1: PASS\n";
