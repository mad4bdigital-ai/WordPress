<?php
/** Native Staging OAuth revocation remote bridge: plan, approval, CAS, deny and readback. */
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', '/tmp/mad4b-google-revoke-test/' );
class WP_Error {
    private $code;
    public function __construct( $code, $message = '' ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function add_action( $name, $callback, $priority = 10 ) {}
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function current_user_can( $cap ) { return 'manage_options' === $cap; }
$GLOBALS['mad4b_test_options'] = array();
function get_option( $key, $default = false ) { return $GLOBALS['mad4b_test_options'][ $key ] ?? $default; }
function wp_register_ability( $name, $def ) { $GLOBALS['mad4b_revoke_abilities'][ $name ] = $def; }
function wp_has_ability( $name ) { return isset( $GLOBALS['mad4b_revoke_abilities'][ $name ] ); }
class MAD4B_SCP_Policy { public static function can_read() { return true; } }
class MAD4B_SCP_Site_Profile {
    public static $environment = 'staging';
    public static function status() {
        return array( 'configured_environment' => self::$environment,
            'wordpress_environment' => self::$environment,
            'origin_match' => true, 'environment_match' => true,
            'authority_ready' => true, 'mutation_pending_audit' => false,
            'profile_digest' => str_repeat( 'e', 64 ),
            'site_uuid' => '12345678-abcd-abcd-abcd-123456789abc', 'revision' => 5 );
    }
}
class MAD4B_SCP_Google_Drive_Context {
    const TOKEN_OPTION = 'fixture_google_option';
    public static $pending = true;
    public static $last_attempt_at = '';
    public static $calls = 0;
    public static $fail = false;
    public static $partial_cleanup = false;
    public static function connection_status() {
        return array( 'revocation_pending' => self::$pending, 'connected' => self::$pending,
            'read_available' => false, 'write_available' => false,
            'revocation_error' => 'google_revocation_http_400',
            'revocation_attempted_at' => self::$last_attempt_at );
    }
    public static function disconnect() {
        ++self::$calls;
        if ( self::$fail ) {
            // Real provider persists the encrypted pending record after 400,
            // changing the plan's grant CAS; retry requires a fresh approval.
            $GLOBALS['mad4b_test_options'][ self::TOKEN_OPTION ]['revocation_error'] = 'google_revocation_http_400';
            self::$last_attempt_at = gmdate( 'c', time() );
            $GLOBALS['mad4b_test_options'][ self::TOKEN_OPTION ]['revocation_generation'] = self::$calls;
            return new WP_Error( 'mad4b_google_drive_remote_revocation_unconfirmed' );
        }
        self::$pending = false;
        if ( self::$partial_cleanup ) return array( 'connected' => false, 'remote_revocation_confirmed' => true );
        unset( $GLOBALS['mad4b_test_options'][ self::TOKEN_OPTION ] );
        return array( 'connected' => false, 'remote_revocation_confirmed' => true );
    }
}
class MAD4B_SCP_Site_Profile_Enrollment {
    public static function can_execute( $input = null ) { return true; }
}
class MAD4B_SCP_Local_OAuth_Server {
    const CHATGPT_CIMD_CLIENT_ID = 'chatgpt-fixture';
}
class MAD4B_SCP_OAuth_Resource_Bridge {
    public static function verified_bearer_client_is( $id ) { return $id === 'chatgpt-fixture'; }
}
// Exercise the actual callback proof, not a stub authorizer which permits
// repeated claims. The durable outer claim is fixture evidence only.
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-authorization.php';
function invoke_remote_retry( $input, $overrides = array(), $name = 'mad4b/google-revocation-retry' ) {
    $claim = array_merge( array( 'allowed' => true, 'reason_code' => 'execution_claimed',
        'approval_required' => true, 'approval_claimed' => true,
        'approval_ticket_id' => $input['_mad4b_approval_ticket_id'] ?? '' ), $overrides );
    $invoke = new ReflectionMethod( 'MAD4B_SCP_Authorization', 'invoke_claimed_callback' );
    $invoke->setAccessible( true );
    return $invoke->invoke( null, $name, $claim, $input,
        array( 'MAD4B_SCP_Google_Revocation_Remote', 'retry' ) );
}
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-google-revocation-remote.php';
$check = static function ( $truth, $description ) {
    if ( ! $truth ) { fwrite( STDERR, "FAIL: $description\n" ); exit( 1 ); }
};
MAD4B_SCP_Google_Revocation_Remote::register_abilities();
MAD4B_SCP_Google_Drive_Context::$last_attempt_at = gmdate( 'c', time() - 180 );
$recent = MAD4B_SCP_Google_Revocation_Remote::retry_admission(
    array( 'connected' => true, 'revocation_pending' => true, 'read_available' => false,
        'write_available' => false, 'revocation_attempted_at' => gmdate( 'c', time() - 12 ) ), time() );
$check( ! $recent['eligible'] && $recent['retry_after_seconds'] > 0
    && 'google_revocation_retry_cooldown' === $recent['blocker'], 'Recent revoke retry is throttled' );
$invalid = MAD4B_SCP_Google_Revocation_Remote::retry_admission(
    array( 'connected' => true, 'revocation_pending' => true,
        'revocation_attempted_at' => 'invalid-timestamp' ), time() );
$check( ! $invalid['eligible'] && 'google_revocation_attempt_timestamp_invalid' === $invalid['blocker'],
    'Malformed persisted revocation timestamp fails closed' );
$old_stamp = MAD4B_SCP_Google_Drive_Context::$last_attempt_at;
MAD4B_SCP_Google_Drive_Context::$last_attempt_at = gmdate( 'c', time() - 10 );
$waiting = MAD4B_SCP_Google_Revocation_Remote::status();
$check( 'PENDING_COOLDOWN' === $waiting['state']
    && ! $waiting['retry_eligible'] && $waiting['retry_after_seconds'] > 0
    && '' === $waiting['next_ability'], 'Remote status does not advertise Retry during cooldown' );
$blocked_plan = MAD4B_SCP_Google_Revocation_Remote::plan();
$check( is_wp_error( $blocked_plan ) &&
    'mad4b_google_revoke_retry_cooldown' === $blocked_plan->get_error_code(),
    'Remote plan refuses recent Google revoke attempt' );
MAD4B_SCP_Google_Drive_Context::$last_attempt_at = 'malformed';
$manual = MAD4B_SCP_Google_Revocation_Remote::status();
$check( 'PENDING_OPERATOR_REVIEW' === $manual['state'] && ! $manual['retry_eligible'],
    'Unparseable revocation timestamp requires operator review' );
MAD4B_SCP_Google_Drive_Context::$last_attempt_at = $old_stamp;
$ready = MAD4B_SCP_Google_Revocation_Remote::status();
$check( 'PENDING_SAFE_RETRY' === $ready['state'] && $ready['retry_eligible'],
    'Completed cooldown permits a fresh owner approval plan' );

$schema = $GLOBALS['mad4b_revoke_abilities'][ MAD4B_SCP_Google_Revocation_Remote::RETRY ]['input_schema'];
$check( ! in_array( '_mad4b_approval_ticket_id', $schema['required'], true )
    && isset( $schema['properties']['_mad4b_approval_ticket_id'] ),
    'Approval planner can create a ticket without requiring a pre-existing ticket' );
$no_token = MAD4B_SCP_Google_Revocation_Remote::plan();
$check( is_wp_error( $no_token ), 'Missing encrypted pending grant is blocked' );
$GLOBALS['mad4b_test_options'][ MAD4B_SCP_Google_Drive_Context::TOKEN_OPTION ] =
    array( 'contract' => 'fixture', 'ciphertext' => 'fixture-secret-not-for-output' );
$planned = MAD4B_SCP_Google_Revocation_Remote::plan();
$check( ! is_wp_error( $planned ) && $planned['eligible']
    && $planned['production_authorized'] === false
    && false === strpos( json_encode( $planned ), 'fixture-secret' ),
    'Plan remains Staging-only and never reveals encrypted token payload' );
$input = array(
    'expected_plan_sha256' => $planned['plan_sha256'],
    'expected_site_uuid' => $planned['expected_site_uuid'],
    'expected_profile_digest' => $planned['expected_profile_digest'],
    'expected_profile_revision' => $planned['expected_profile_revision'],
    'confirmation' => $planned['confirmation'],
);
$blocked = invoke_remote_retry( $input );
$check( is_wp_error( $blocked ) && MAD4B_SCP_Google_Drive_Context::$calls === 0,
    'Missing real one-time approval cannot reach Google provider' );
$input['_mad4b_approval_ticket_id'] = '12345678-abcd-abcd-abcd-123456789abc';
$direct = MAD4B_SCP_Google_Revocation_Remote::retry( $input );
$check( is_wp_error( $direct ) && 'mad4b_google_revoke_claimed_callback_required' === $direct->get_error_code()
    && MAD4B_SCP_Google_Drive_Context::$calls === 0, 'Direct retry cannot claim its own execution authority' );
foreach ( array( array( 'approval_claimed' => false ), array( 'reason_code' => 'preflight_allowed' ),
    array( 'approval_ticket_id' => 'ffffffff-ffff-ffff-ffff-ffffffffffff' ) ) as $bad_claim ) {
    $denied = invoke_remote_retry( $input, $bad_claim );
    $check( is_wp_error( $denied ) && MAD4B_SCP_Google_Drive_Context::$calls === 0,
        'Missing, preflight-only or foreign ticket proof cannot contact Google' );
}
$foreign = invoke_remote_retry( $input, array(), 'mad4b/deployment-binding-native-apply' );
$check( is_wp_error( $foreign ) && MAD4B_SCP_Google_Drive_Context::$calls === 0,
    'Native binding claim cannot authorize Google revocation' );

$GLOBALS['mad4b_test_options'][ MAD4B_SCP_Google_Drive_Context::TOKEN_OPTION ]['ciphertext'] = 'changed';
$blocked = invoke_remote_retry( $input );
$check( is_wp_error( $blocked ) && MAD4B_SCP_Google_Drive_Context::$calls === 0,
    'Encrypted grant CAS drift is rejected before provider call' );
$GLOBALS['mad4b_test_options'][ MAD4B_SCP_Google_Drive_Context::TOKEN_OPTION ]['ciphertext'] = 'fixture-secret-not-for-output';
MAD4B_SCP_Google_Drive_Context::$fail = true;
$blocked = invoke_remote_retry( $input );
$check( is_wp_error( $blocked ) && MAD4B_SCP_Google_Drive_Context::$pending
    && isset( $GLOBALS['mad4b_test_options'][ MAD4B_SCP_Google_Drive_Context::TOKEN_OPTION ] ),
    'Remote HTTP 400 uncertainty cannot clear pending token or grant' );
MAD4B_SCP_Google_Drive_Context::$fail = false;
$stale = invoke_remote_retry( $input );
$check( is_wp_error( $stale ) && MAD4B_SCP_Google_Drive_Context::$calls === 1,
    'Second retry MUST refuse prior plan and approval after HTTP 400 persisted new pending evidence' );
$cooldown = MAD4B_SCP_Google_Revocation_Remote::plan();
$check( is_wp_error( $cooldown ) && 'mad4b_google_revoke_retry_cooldown' === $cooldown->get_error_code()
    && MAD4B_SCP_Google_Drive_Context::$calls === 1,
    'Remote cannot bypass native Google revoke backoff immediately after HTTP 400' );
MAD4B_SCP_Google_Drive_Context::$last_attempt_at = gmdate( 'c', time() - 120 );
$next = MAD4B_SCP_Google_Revocation_Remote::plan();
$check( ! is_wp_error( $next ) && $next['plan_sha256'] !== $planned['plan_sha256'],
    'A retry after uncertain provider response requires a fresh plan and one-time approval' );
$input['expected_plan_sha256'] = $next['plan_sha256'];
$input['_mad4b_approval_ticket_id'] = '87654321-dcba-dcba-dcba-abcdef123456';
MAD4B_SCP_Google_Drive_Context::$partial_cleanup = true;
$unverified = invoke_remote_retry( $input );
$check( is_wp_error( $unverified )
    && 'mad4b_google_revoke_readback_unconfirmed' === $unverified->get_error_code()
    && isset( $GLOBALS['mad4b_test_options'][ MAD4B_SCP_Google_Drive_Context::TOKEN_OPTION ] ),
    'Provider success without independent local deletion is not certified' );
MAD4B_SCP_Google_Drive_Context::$partial_cleanup = false;
MAD4B_SCP_Google_Drive_Context::$pending = true;
$input['_mad4b_approval_ticket_id'] = '13572468-abcd-abcd-abcd-abcdef654321';
$result = invoke_remote_retry( $input );
$check( ! is_wp_error( $result ) && $result['remote_revocation_confirmed']
    && empty( $GLOBALS['mad4b_test_options'][ MAD4B_SCP_Google_Drive_Context::TOKEN_OPTION ] )
    && ! MAD4B_SCP_Google_Drive_Context::$pending, 'Explicit confirmed revoke succeeds with token deleted' );
$check( ! MAD4B_SCP_Authorization::google_revocation_callback_is_claimed( $input ),
    'Claim proof is erased after provider completion' );
$repeat = invoke_remote_retry( $input );
$check( is_wp_error( $repeat ), 'A completed revoke never reexecutes on stale approval' );
MAD4B_SCP_Site_Profile::$environment = 'production';
$check( is_wp_error( MAD4B_SCP_Google_Revocation_Remote::status() ),
    'No Production read authority through the Staging-only recovery contract' );
echo "PASS: governed remote Google pending-revoke, approval, CAS, failure and readback\n";
