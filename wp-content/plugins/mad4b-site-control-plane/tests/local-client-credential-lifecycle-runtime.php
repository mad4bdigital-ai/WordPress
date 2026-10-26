<?php
/**
 * Hermetic WordPress option/credential mocks for local client lifecycle.
 * Never connects to a WordPress database or issues a real application password.
 */
$dir = sys_get_temp_dir() . '/mad4b-local-client-fixture-' . bin2hex( random_bytes( 6 ) );
$vaultdir = $dir . '/private';
$www = $dir . '/site';
if ( ! mkdir( $vaultdir, 0700, true ) || ! mkdir( $www, 0700, true ) ) exit( 1 );
define( 'ABSPATH', $www . '/' );
define( 'MAD4B_LOCAL_CLIENT_SITE_UUID', '49c562d1-8f2f-456f-b454-26816c6ba4cb' );
define( 'MAD4B_LOCAL_CLIENT_PROVISION_ENABLED', true );
define( 'MAD4B_LOCAL_CLIENT_VAULT_PATH', $vaultdir . '/local-mcp.json' );
$GLOBALS['lc_options'] = array();
$GLOBALS['lc_nonce'] = true;
$GLOBALS['lc_created'] = 0;
$GLOBALS['lc_revoked'] = 0;
$GLOBALS['lc_user'] = 4;
function wp_get_environment_type() { return 'local'; }
function current_user_can( $cap ) { return 'manage_options' === $cap; }
function get_current_user_id() { return $GLOBALS['lc_user']; }
function add_action( $hook, $callback ) {}
function get_option( $name, $default = false ) { return array_key_exists( $name, $GLOBALS['lc_options'] ) ? $GLOBALS['lc_options'][$name] : $default; }
function add_option( $name, $value, $unused = '', $autoload = false ) {
    if ( isset( $GLOBALS['lc_options'][$name] ) ) return false;
    $GLOBALS['lc_options'][$name] = $value;
    return true;
}
function update_option( $name, $value, $autoload = null ) {
    $GLOBALS['lc_options'][$name] = $value;
    return true;
}
function delete_option( $name ) {
    unset( $GLOBALS['lc_options'][$name] );
    return true;
}
function check_admin_referer( $action ) {
    if ( ! $GLOBALS['lc_nonce'] ) throw new RuntimeException( 'NONCE_INVALID' );
    return true;
}
function wp_safe_redirect( $url ) { throw new RuntimeException( 'REDIRECT:' . $url ); }
function wp_die( $message, $title = '', $args = array() ) { throw new RuntimeException( 'DENIED:' . $message ); }
function admin_url( $url ) { return '/wp-admin/' . $url; }
function sanitize_text_field( $str ) { return (string) $str; }
function wp_unslash( $str ) { return (string) $str; }
function wp_get_current_user() { return (object) array( 'ID' => $GLOBALS['lc_user'], 'user_login' => 'fixture-local-admin' ); }
function wp_is_application_passwords_available_for_user( $user ) { return true; }
function is_wp_error( $value ) { return false; }
function wp_json_encode( $value ) { return json_encode( $value ); }
class MAD4B_SCP_Site_Profile {
    public static function site_uuid() { return MAD4B_LOCAL_CLIENT_SITE_UUID; }
    public static function origin_enrolled() { return true; }
    public static function site_urls_match_enrollment() { return true; }
}
class MAD4B_SCP_Policy {
    public static function can_connect_user( $id ) { return 4 === $id; }
    public static function mutation_gate_status() { return array( 'effective' => false ); }
}
// Local client enrollment now binds the authenticated WordPress Application
// Password to the original AI reviewer. The isolated test must model that
// contract; omitting it caused a false fatal before any expected assertions.
class MAD4B_SCP_Context_Authority {
    public static function review_policy() {
        return array('mode'=>'ai_only','ai_agent_public_id'=>'237e4567-e89b-12d3-a456-426614174000');
    }
}
class MAD4B_SCP_Agent_Registry {
    public static function bind_subject($agent,$type,$fingerprint,$label='') {
        if($agent!=='237e4567-e89b-12d3-a456-426614174000' || $type!=='wp_app_password'
            || !preg_match('/^[a-f0-9]{64}$/D',$fingerprint))return false;
        $GLOBALS['lc_bound_agent']=$agent;
        $GLOBALS['lc_bound_fingerprint']=$fingerprint;
        return true;
    }
}
class WP_Application_Passwords {
    public static function create_new_application_password( $user, $args ) {
        $GLOBALS['lc_created']++;
        return array( 'NOT_A_REAL_PASSWORD', array( 'uuid' => '00000000-1111-2222-3333-444444444444' ) );
    }
    public static function delete_application_password( $user, $uuid ) {
        if ( 4 !== $user || '00000000-1111-2222-3333-444444444444' !== $uuid ) return false;
        $GLOBALS['lc_revoked']++;
        return true;
    }
}
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-local-client.php';
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-local-client-admin.php';

function ok_local( $condition, $label ) {
    if ( ! $condition ) { fwrite( STDERR, 'FAIL ' . $label . PHP_EOL ); exit( 1 ); }
}
function action_local( $fn, $expected ) {
    try { $fn(); }
    catch ( RuntimeException $e ) {
        ok_local( 0 === strpos( $e->getMessage(), $expected ), $expected . ' instead got ' . $e->getMessage() );
        return;
    }
    ok_local( false, 'action should have returned through a redirected/denied response' );
}
ok_local( MAD4B_SCP_Local_Client::bound(), 'local site identity bound' );
ok_local( ! MAD4B_SCP_Local_Client::can_local_write(), 'existing mutation gate remains off' );
action_local( array( 'MAD4B_SCP_Local_Client_Admin', 'request' ), 'REDIRECT:' );
$pending = get_option( MAD4B_SCP_Local_Client_Admin::PENDING_OPTION, array() );
ok_local( is_array( $pending ) && 32 === strlen( $pending['id'] ) && $pending['user_id'] === 4, 'locally created request is user-bound' );
ok_local( ( $pending['expires'] - $pending['created'] ) === MAD4B_SCP_Local_Client_Admin::REQUEST_TTL, 'five-minute request TTL' );
ok_local( $GLOBALS['lc_created'] === 0, 'request creation did not issue credentials' );
action_local( array( 'MAD4B_SCP_Local_Client_Admin', 'request' ), 'DENIED:' );
ok_local( $GLOBALS['lc_created'] === 0, 'parallel pending request prevented' );
$_POST = array( 'request_id' => 'invalid' );
action_local( array( 'MAD4B_SCP_Local_Client_Admin', 'provision' ), 'DENIED:' );
ok_local( ! file_exists( MAD4B_LOCAL_CLIENT_VAULT_PATH ), 'wrong request denied without vault' );
$_POST = array( 'request_id' => $pending['id'] );
$GLOBALS['lc_nonce'] = false;
action_local( array( 'MAD4B_SCP_Local_Client_Admin', 'provision' ), 'NONCE_INVALID' );
$GLOBALS['lc_nonce'] = true;
action_local( array( 'MAD4B_SCP_Local_Client_Admin', 'provision' ), 'REDIRECT:' );
ok_local( $GLOBALS['lc_created'] === 1, 'exact user-approved credential mock issued once' );
ok_local( ($GLOBALS['lc_bound_agent']??'')==='237e4567-e89b-12d3-a456-426614174000',
    'issued credential is bound only to original AI Agent' );
ok_local( preg_match('/^[a-f0-9]{64}$/D',(string)($GLOBALS['lc_bound_fingerprint']??''))===1,
    'credential identity is bound by exact SHA-256 fingerprint without exposing its value' );

ok_local( is_file( MAD4B_LOCAL_CLIENT_VAULT_PATH ), 'credential stored outside web root' );
clearstatcache( true, MAD4B_LOCAL_CLIENT_VAULT_PATH );
ok_local( ( fileperms( MAD4B_LOCAL_CLIENT_VAULT_PATH ) & 0077 ) === 0, 'vault permission is owner-only' );
$record = json_decode( file_get_contents( MAD4B_LOCAL_CLIENT_VAULT_PATH ), true );
ok_local( $record['uuid'] === '00000000-1111-2222-3333-444444444444' && $record['user_id'] === 4, 'exact revocable credential metadata present' );
ok_local( get_option( MAD4B_SCP_Local_Client_Admin::PENDING_OPTION, false ) === false, 'pending request consumed' );
action_local( array( 'MAD4B_SCP_Local_Client_Admin', 'provision' ), 'DENIED:' );
ok_local( $GLOBALS['lc_created'] === 1, 'repeated provision did not issue a second credential' );
$GLOBALS['lc_user'] = 5;
action_local( array( 'MAD4B_SCP_Local_Client_Admin', 'revoke' ), 'DENIED:' );
ok_local( $GLOBALS['lc_revoked'] === 0, 'different user cannot revoke' );
$GLOBALS['lc_user'] = 4;
action_local( array( 'MAD4B_SCP_Local_Client_Admin', 'revoke' ), 'REDIRECT:' );
ok_local( $GLOBALS['lc_revoked'] === 1 && ! file_exists( MAD4B_LOCAL_CLIENT_VAULT_PATH ), 'correct credential revoked and vault deleted' );
$events = array_column( get_option( MAD4B_SCP_Local_Client_Admin::AUDIT_OPTION ), 'event' );
ok_local( $events === array( 'request_created', 'credential_issued', 'credential_revoked' ), 'bounded credential-free lifecycle audit' );
rmdir( $vaultdir );
rmdir( $www );
rmdir( $dir );
echo "mad4b.local-client-credential-lifecycle.v1: PASS\n";
