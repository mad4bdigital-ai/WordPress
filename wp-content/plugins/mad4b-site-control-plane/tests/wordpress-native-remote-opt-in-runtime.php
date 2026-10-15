<?php
/** Read/write regression for exact WordPress-native admin consent over MCP.
 *  No WordPress bootstrap, GitHub network, package build, or production write.
 */
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );
class WP_Error {
    private $code;
    public function __construct( $code, $message = '' ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_json_encode( $x ) { return json_encode( $x ); }
function add_action( ...$args ) {}
$GLOBALS['registered_abilities'] = array();
function wp_has_ability( $name ) { return isset( $GLOBALS['registered_abilities'][$name] ); }
function wp_register_ability( $name, $args ) { $GLOBALS['registered_abilities'][$name] = $args; }
$GLOBALS['opts'] = array();
function get_option( $name, $fallback = false ) {
    return array_key_exists( $name, $GLOBALS['opts'] ) ? $GLOBALS['opts'][$name] : $fallback;
}
function update_option( $name, $value, $autoload = false ) { $GLOBALS['opts'][$name] = $value; return true; }
function wp_get_environment_type() { return $GLOBALS['test_wp_environment']; }
function current_user_can( $cap ) { return (bool) $GLOBALS['owner']; }
function get_current_user_id() { return 73; }
function is_multisite() { return (bool) $GLOBALS['multisite']; }
function check( $yes, $name ) { if ( !$yes ) { fwrite( STDERR, "FAIL: $name\n" ); exit( 1 ); } }
class MAD4B_SCP_Policy {
    public static function can_read() { return true; }
    public static function can_mutate() { return true; }
}
class MAD4B_SCP_Site_Profile {
    public static function status() { return $GLOBALS['site']; }
    public static function user_is_enrolled( $id ) { return 73 === $id; }
}
class MAD4B_SCP_OAuth_Resource_Bridge {
    const AUTHORITY_STEP_UP_SCOPE = 'mad4b:authority:step-up';
    public static function verified_bearer_active() { return (bool) $GLOBALS['oauth']; }
    public static function verified_bearer_has_scope( $scope ) { return (bool) $GLOBALS['oauth']; }
    public static function verified_bearer_client_is( $id ) { return (bool) $GLOBALS['oauth']; }
}
class MAD4B_SCP_Local_OAuth_Server {
    const CHATGPT_CIMD_CLIENT_ID = 'https://chatgpt.com/oauth/client.json';
}
class MAD4B_SCP_Authorization {
    public static function execution_callback_started( $name ) {
        return $GLOBALS['claimed'] && 'mad4b/wordpress-native-candidate-opt-in-apply' === $name;
    }
}
class MAD4B_SCP_Audit {
    public static function record( $name, $fields, $status ) {
        if ( $GLOBALS['audit_fail'] ) return new WP_Error( 'audit_failure' );
        $GLOBALS['audits'][] = array( $name, $fields, $status );
        return array( 'saved' => true );
    }
}
$GLOBALS['test_wp_environment'] = 'staging';
$GLOBALS['multisite'] = false;
$GLOBALS['owner'] = true;
$GLOBALS['oauth'] = true;
$GLOBALS['claimed'] = false;
$GLOBALS['audit_fail'] = false;
$GLOBALS['audits'] = array();
$GLOBALS['site'] = array(
    'configured_environment' => 'staging', 'environment' => 'staging',
    'configured' => true, 'authority_ready' => true, 'origin_match' => true,
    'environment_match' => true, 'profile_environment_authoritative' => true,
    'site_uuid' => 'd745d81f-6fc4-5c6a-99dd-d953c92137bf',
    'profile_digest' => str_repeat( 'a', 64 ),
    'canonical_origin' => 'https://staging.example.test',
    'current_origin' => 'https://staging.example.test',
    'wordpress_environment' => 'staging',
    'wordpress_environment_explicit' => true,
    'mutation_pending_audit' => false, 'reenrollment_required' => false,
    'foreign_profile_detected' => false,
);
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-wordpress-native-opt-in.php';
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-wordpress-native-remote-opt-in.php';
$c = 'MAD4B_SCP_WordPress_Native_Remote_Opt_In';
$c::register_abilities();
$c::register_abilities();
check( count( $GLOBALS['registered_abilities'] ) === 2, 'two registered remote capabilities, idempotent' );
$p = $GLOBALS['registered_abilities'][$c::PLAN];
$a = $GLOBALS['registered_abilities'][$c::APPLY];
check( $p['meta']['mcp']['surface'] === 'read' &&
    $p['meta']['annotations']['readonly'] === true, 'plan is an exact read' );
check( $a['meta']['mcp']['surface'] === 'admin' &&
    $a['meta']['mcp']['production_mutation_allowed'] === false &&
    $a['meta']['annotations']['readonly'] === false, 'admin apply is governed, not read' );
check( $a['input_schema']['additionalProperties'] === false &&
    count( $a['input_schema']['required'] ) === 3, 'mutation schema fixed and narrow' );
$before = $c::plan( array( 'mode' => 'enable' ) );
check( $before['eligible'] === true && $before['current_enabled'] === false &&
    $before['mutation_performed'] === false &&
    $before['required_mutation_ability'] === $c::APPLY, 'safe eligible staging plan' );
check( count( $GLOBALS['opts'] ) === 0, 'read plan cannot change setting' );
$input = array( 'mode' => 'enable', 'expected_plan_sha256' => $before['plan_sha256'],
    'confirmation' => $before['confirmation'] );
check( is_wp_error( $c::apply( $input ) ), 'direct internal call denied without canonical claim' );
$GLOBALS['claimed'] = true;
$GLOBALS['oauth'] = false;
check( is_wp_error( $c::apply( $input ) ), 'OAuth step-up missing denied' );
$GLOBALS['oauth'] = true;
$GLOBALS['owner'] = false;
check( is_wp_error( $c::apply( $input ) ), 'unprivileged remote caller denied' );
$GLOBALS['owner'] = true;
$bad = $input; $bad['extra_option'] = 'admin_email';
check( is_wp_error( $c::apply( $bad ) ), 'unknown options / arbitrary settings denied' );
$bad = $input; $bad['confirmation'] = 'UNVERIFIED';
check( is_wp_error( $c::apply( $bad ) ), 'wrong consent denied' );
$enabled = $c::apply( $input );
check( is_array( $enabled ) && $enabled['state'] === 'VERIFIED'
    && $enabled['enabled'] === true && $enabled['no_build_or_install_started'] &&
    count( $GLOBALS['audits'] ) === 1 &&
    MAD4B_SCP_WordPress_Native_Opt_In::enabled(), 'opt-in activated and readback verified' );
check( is_wp_error( $c::apply( $input ) ), 'replay of stale plan rejected' );
$off = $c::plan( array( 'mode' => 'disable' ) );
check( $off['eligible'] && !$off['already_current'], 'fresh disable plan' );
$disable = array( 'mode' => 'disable',
    'expected_plan_sha256' => $off['plan_sha256'],
    'confirmation' => $off['confirmation'] );
$GLOBALS['audit_fail'] = true;
check( is_wp_error( $c::apply( $disable ) ) &&
    MAD4B_SCP_WordPress_Native_Opt_In::enabled(), 'audit failure restores previous option' );
$GLOBALS['audit_fail'] = false;
$disabled = $c::apply( $disable );
check( is_array( $disabled ) && $disabled['state'] === 'VERIFIED' &&
    $disabled['enabled'] === false, 'exact disable with fresh plan' );
$GLOBALS['test_wp_environment'] = 'production';
$production = $c::plan( array( 'mode' => 'enable' ) );
check( !$production['eligible'] && is_wp_error( $c::apply( array(
    'mode' => 'enable', 'expected_plan_sha256' => $production['plan_sha256'],
    'confirmation' => $production['confirmation'] ) ) ), 'Production forbidden' );
$GLOBALS['test_wp_environment'] = 'staging';
$GLOBALS['multisite'] = true;
check( !$c::plan( array( 'mode' => 'enable' ) )['eligible'], 'multisite forbidden' );
echo "PASS: remote opt-in exact Staging owner consent, schema, OAuth, claimed approval, audit, CAS and Production denial\n";
