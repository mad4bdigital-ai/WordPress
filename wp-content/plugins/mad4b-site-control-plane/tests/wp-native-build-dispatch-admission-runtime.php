<?php
/** First-party native builder: error-specific, side-effect-free Owner/OAuth preflight. */
if ( PHP_VERSION_ID < 70400 ) { fwrite( STDERR, "PHP 7.4 required\n" ); exit( 1 ); }
define( 'ABSPATH', __DIR__ );
class WP_Error {
    private $code;
    public function __construct( $code, $message = '', $data = array() ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error( $result ) { return $result instanceof WP_Error; }
function current_user_can( $cap ) { return $cap === 'manage_options' && $GLOBALS['is_admin']; }
function get_current_user_id() { return 17; }
function wp_get_environment_type() { return $GLOBALS['wp_environment']; }
final class MAD4B_SCP_Site_Profile {
    public static function status() { return array(
        'environment' => $GLOBALS['environment'], 'configured_environment' => 'staging',
        'authority_ready' => true, 'origin_match' => true,
        'site_uuid' => '49c562d1-8f2f-456f-b454-26816c6ba4cb',
        'profile_digest' => str_repeat( 'a', 64 ),
        'canonical_origin' => 'https://staging.example.test',
    ); }
    public static function user_is_enrolled( $user_id ) { return 17 === $user_id && $GLOBALS['enrolled']; }
}
final class MAD4B_SCP_WordPress_Native_Opt_In {
    public static function enabled() { return true; }
}
final class MAD4B_SCP_Policy {
    public static function can_mutate() { return $GLOBALS['mutation_allowed']; }
}
final class MAD4B_SCP_OAuth_Resource_Bridge {
    const AUTHORITY_STEP_UP_SCOPE = 'mad4b:authority:step-up';
    public static function verified_bearer_active() { return $GLOBALS['bearer']; }
    public static function verified_bearer_has_scope( $scope ) {
        return self::AUTHORITY_STEP_UP_SCOPE === $scope && $GLOBALS['step_up'];
    }
    public static function verified_bearer_client_is( $client ) {
        return MAD4B_SCP_Local_OAuth_Server::CHATGPT_CIMD_CLIENT_ID === $client && $GLOBALS['client'];
    }
}
final class MAD4B_SCP_Local_OAuth_Server {
    const CHATGPT_CIMD_CLIENT_ID = 'chatgpt-fixture';
}
final class MAD4B_SCP_Authorization {}
$GLOBALS['wp_environment'] = 'staging'; $GLOBALS['environment'] = 'staging';
$GLOBALS['is_admin'] = true; $GLOBALS['enrolled'] = true;
$GLOBALS['mutation_allowed'] = true; $GLOBALS['bearer'] = true;
$GLOBALS['step_up'] = true; $GLOBALS['client'] = true;
require __DIR__ . '/../includes/class-mad4b-scp-wp-native-build.php';
$check = static function( $expected ) {
    $result = MAD4B_SCP_WP_Native_Build::can_start();
    $actual = is_wp_error( $result ) ? $result->get_error_code() : 'allowed';
    if ( $actual !== $expected ) throw new RuntimeException( 'Native build preflight expected ' . $expected . ' but got ' . $actual );
};
$check( 'allowed' );
$GLOBALS['step_up'] = false;
$check( 'mad4b_native_step_up_required' );
$GLOBALS['step_up'] = true;
$GLOBALS['bearer'] = false;
$check( 'mad4b_native_owner_oauth_required' );
$GLOBALS['bearer'] = true;
$GLOBALS['client'] = false;
$check( 'mad4b_native_oauth_client_mismatch' );
$GLOBALS['client'] = true;
$GLOBALS['enrolled'] = false;
$check( 'mad4b_native_owner_enrollment_required' );
$GLOBALS['enrolled'] = true;
$GLOBALS['is_admin'] = false;
$check( 'mad4b_native_owner_admin_required' );
$GLOBALS['is_admin'] = true;
$GLOBALS['mutation_allowed'] = false;
$check( 'mad4b_native_mutation_policy_denied' );
$GLOBALS['mutation_allowed'] = true;
$GLOBALS['environment'] = 'production';
$check( 'mad4b_native_staging_required' );
$GLOBALS['environment'] = 'staging';
$GLOBALS['wp_environment'] = 'production';
$check( 'mad4b_native_staging_required' );
$GLOBALS['wp_environment'] = 'staging';
$check( 'allowed' );
echo "PASS native builder admission: enrolled owner, OAuth identity, Step-Up, client, policy, exact Staging; no Cron or tickets\n";
