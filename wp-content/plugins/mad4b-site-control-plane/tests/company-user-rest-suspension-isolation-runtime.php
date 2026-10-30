<?php
/** Hermetic company user security: no global REST auth interception, suspended REST calls still blocked. */
define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['qa31_hooks'] = array();
$GLOBALS['qa31_user'] = 0;
$GLOBALS['qa31_suspended'] = array();
function add_filter( $name, $callback, $priority = 10, $accepted = 1 ) {
    $GLOBALS['qa31_hooks'][ $name ][] = array( 'callback'=>$callback, 'priority'=>$priority, 'accepted'=>$accepted );
    return true;
}
function add_action( $name, $callback, $priority = 10, $accepted = 1 ) {
    return add_filter( $name, $callback, $priority, $accepted );
}
function get_current_user_id() { return $GLOBALS['qa31_user']; }
function get_user_meta( $id, $key, $single = false ) {
    return $GLOBALS['qa31_suspended'][ $id ] ?? false;
}
class WP_Error {
    private $code;
    function __construct( $code, $message, $data = array() ) { $this->code = $code; }
    function get_error_code() { return $this->code; }
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-company-user-lifecycle.php';
$fail = static function( $why ){ fwrite(STDERR, "FAIL company_user_rest_isolation: $why\n"); exit(1); };
if ( ! empty( $GLOBALS['qa31_hooks']['rest_authentication_errors'] ) ) $fail('global REST auth callback mounted');
$hooks = $GLOBALS['qa31_hooks']['rest_pre_dispatch'] ?? array();
if ( count( $hooks ) !== 1 || $hooks[0]['priority'] !== 95 ) $fail('scoped dispatch guard absent');
if ( ! isset( $GLOBALS['qa31_hooks']['authenticate'], $GLOBALS['qa31_hooks']['wp_authenticate_application_password_errors'] ) ) $fail('login and application password guards lost');
$original = 'no prior dispatcher response';
if ( MAD4B_SCP_Company_User_Lifecycle::guardREST( $original ) !== $original ) $fail('guest REST denied');
$GLOBALS['qa31_user'] = 12;
if ( MAD4B_SCP_Company_User_Lifecycle::guardREST( $original ) !== $original ) $fail('non-suspended REST denied');
$GLOBALS['qa31_suspended'][12] = array('at'=>'test');
$blocked = MAD4B_SCP_Company_User_Lifecycle::guardREST( $original );
if ( ! is_wp_error($blocked) || $blocked->get_error_code() !== 'company_user_suspended' ) $fail('suspended REST user not blocked');
$prior = new WP_Error('upstream_auth_denied','prior decision');
if ( MAD4B_SCP_Company_User_Lifecycle::guardREST($prior) !== $prior ) $fail('earlier error overwritten');
$GLOBALS['qa31_suspended'][12] = false;
if ( MAD4B_SCP_Company_User_Lifecycle::guardREST($original) !== $original ) $fail('restored user remains blocked');
echo "COMPANY_USER_REST_SUSPENSION_ISOLATION: PASS\n";
