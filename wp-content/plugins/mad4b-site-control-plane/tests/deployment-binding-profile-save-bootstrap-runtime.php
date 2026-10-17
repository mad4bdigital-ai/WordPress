<?php
/* One-step native binding: isolated fake WordPress rooted in temporary files. */
$base = sys_get_temp_dir() . '/mad4b-native-fixture-' . bin2hex( random_bytes( 8 ) );
$root = $base . '/public_html';
if ( ! mkdir( $root, 0700, true ) ) exit( 2 );
file_put_contents( $root . '/wp-load.php', "<?php // fixture\n" );
define( 'ABSPATH', $root . '/' );
$_SERVER['DOCUMENT_ROOT'] = $root;
class WP_Error {
    private $code;
    public function __construct( $code, $message = '' ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function wp_json_encode( $x, $flags = 0 ) { return json_encode( $x, $flags ); }
function add_action( $a, $b, $c = 10 ) {}
function do_action( $a ) {}
$GLOBALS['abilities'] = array();
function wp_register_ability( $name, $spec ) { $GLOBALS['abilities'][ $name ] = $spec; }
function wp_has_ability( $name ) { return isset( $GLOBALS['abilities'][ $name ] ); }
$GLOBALS['admin'] = true;
function current_user_can( $c ) { return $GLOBALS['admin']; }
function get_option( $k, $fallback = null ) { return array_key_exists( $k, $GLOBALS['options'] )
    ? $GLOBALS['options'][$k] : $fallback; }
class MAD4B_SCP_Policy { public static function can_read() { return true; } }
class MAD4B_SCP_Site_Profile_Enrollment {
    public static function can_execute( $input = null ) { return $GLOBALS['admin']; }
}
class MAD4B_SCP_Local_OAuth_Server { const CHATGPT_CIMD_CLIENT_ID = 'test-client'; }
class MAD4B_SCP_OAuth_Resource_Bridge {
    public static function verified_bearer_client_is( $id ) { return true; }
}
class MAD4B_SCP_Authorization {
    public static $approved = true;
    public static $claimed = 0;
    public static $callback_claimed = false;
    public static $exact_input_hash = '';
    public static function native_binding_callback_is_claimed( $input ) {
        return self::$callback_claimed && self::$approved
            && is_array( $input )
            && hash_equals( self::$exact_input_hash, hash( 'sha256', wp_json_encode(
                $input, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) );
    }
    public static function authorize_mutation( $name, $surface, $provider, $input ) {
        return self::$approved ? true : new WP_Error( 'not_approved' );
    }
    public static function permission_result_from_authorization( $decision ) { return $decision; }
    public static function claim_mutation( $name, $surface, $provider, $input ) {
        self::$claimed++;
        return self::$approved ? array( 'allowed' => true ) : new WP_Error( 'not_claimed' );
    }
}
class MAD4B_SCP_Staging_Write_Authority {
    public static $ready = false;
    public static function status() {
        return array( 'state' => self::$ready ? 'ready' : 'blocked',
            'ready' => self::$ready,
            'agent_public_id' => '0b8f7c20-6f75-4401-b33e-a59a14c48895',
            'candidate_binding_match' => self::$ready,
            'exact_grants_existing' => 95, 'write_tool_count' => 96,
            'blockers' => self::$ready ? array() : array( 'grant_reconciliation_incomplete' ) );
    }
}
class MAD4B_SCP_Site_Profile {
    const OPTION = 'mad4b_staging_profile_fixture';
    public static $env = 'staging';
    public static $commits = 0;
    public static $fail_commit = false;
    public static function status() {
        $stored = get_option( self::OPTION );
        $digest = MAD4B_SCP_Deployment_Binding_Native::read_host_secret( $stored['site_uuid'] );
        $has = '' !== $digest;
        $bind = (string) ( $stored['deployment_binding_digest'] ?? '' );
        return array(
            'site_uuid' => $stored['site_uuid'], 'revision' => $stored['revision'],
            'canonical_origin' => $stored['canonical_origin'],
            'profile_digest' => hash( 'sha256', json_encode( $stored ) ),
            'configured_environment' => self::$env, 'wordpress_environment' => self::$env,
            'environment' => self::$env,
            'origin_match' => true, 'environment_match' => true,
            'authority_ready' => true, 'deployment_binding_configured' => $has,
            'deployment_binding_bound' => '' !== $bind,
            'deployment_binding_match' => $has && '' !== $bind
                && hash_equals( hash( 'sha256', $digest ), $bind ),
            'mutation_pending_audit' => false,
        );
    }
    public static function deployment_binding_digest() {
        $stored = get_option( self::OPTION );
        $key = MAD4B_SCP_Deployment_Binding_Native::read_host_secret( $stored['site_uuid'] );
        return $key ? hash( 'sha256', $key ) : '';
    }
    public static function commit_record_with_audit( $before, $after, $ability, $audit, $prefix ) {
        if ( self::$fail_commit || get_option( self::OPTION ) !== $before )
            return new WP_Error( 'audit_cas_denied' );
        $GLOBALS['options'][ self::OPTION ] = $after;
        self::$commits++;
        return array( 'audit' => true );
    }
    public static function reset_cache() {}
}
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-deployment-binding-native.php';

define( 'MAD4B_SCP_FILE', 'mad4b-site-control-plane/mad4b-site-control-plane.php' );
class MAD4B_SCP_Site_Profile_Admin {
    const ACTION_SAVE = 'mad4b_site_profile_save';
    const ACTION_AUTOPILOT = 'mad4b_site_profile_enable_staging_autopilot';
}
$GLOBALS['wp_hook'] = '';
$GLOBALS['wp_is_admin'] = true;
function current_filter() { return $GLOBALS['wp_hook']; }
function plugin_basename($x) { return $x; }
function is_admin() { return $GLOBALS['wp_is_admin']; }
function assert_profile($x,$name){if(!$x){fwrite(STDERR,"FAIL profile setup: $name\n");exit(1);}}
$_SERVER['REQUEST_METHOD'] = 'POST';
$GLOBALS['options'][MAD4B_SCP_Site_Profile::OPTION] = array(
    'site_uuid' => '49c562d1-8f2f-456f-b454-26816c6ba4cb',
    'environment' => 'staging',
    'canonical_origin' => 'https://staging.allroyalegypt.com',
    'revision' => 1,
    'deployment_binding_digest' => '',
    'oauth_user_ids' => array( 42 ),
    'features' => array( 'write' => false, 'skills' => true ),
);
$before=get_option(MAD4B_SCP_Site_Profile::OPTION);
$location=MAD4B_SCP_Deployment_Binding_Native::private_location($before['site_uuid']);
$GLOBALS['wp_hook']='init';
assert_profile(is_wp_error(MAD4B_SCP_Deployment_Binding_Native::profile_save_bootstrap()),
    'unauthenticated / ordinary hook denied');
$GLOBALS['wp_hook']='admin_post_mad4b_site_profile_save';
$_SERVER['REQUEST_METHOD']='GET';
assert_profile(is_wp_error(MAD4B_SCP_Deployment_Binding_Native::profile_save_bootstrap()),
    'GET cannot impersonate nonce-verified form save');
$_SERVER['REQUEST_METHOD']='POST';
MAD4B_SCP_Site_Profile::$env='production';
assert_profile(is_wp_error(MAD4B_SCP_Deployment_Binding_Native::profile_save_bootstrap()),
    'Production profile save cannot mint native Staging key');
MAD4B_SCP_Site_Profile::$env='staging';
$GLOBALS['admin']=false;
assert_profile(is_wp_error(MAD4B_SCP_Deployment_Binding_Native::profile_save_bootstrap()),
    'user without activation capability denied');
$GLOBALS['admin']=true;
$out=MAD4B_SCP_Deployment_Binding_Native::profile_save_bootstrap();
assert_profile(is_array($out) && $out['state']==='BOUND_NEW_REQUEST_READBACK_REQUIRED'
    && MAD4B_SCP_Site_Profile::$commits===1, 'nonce-verified admin profile enrollment bootstraps');
$after=get_option(MAD4B_SCP_Site_Profile::OPTION);
assert_profile($after['revision']===2 && $after['features']===$before['features']
    && empty($after['features']['write']),'Site Profile authority never widened');
$repeated=MAD4B_SCP_Deployment_Binding_Native::profile_save_bootstrap();
assert_profile(is_array($repeated) && $repeated['state']==='EXISTING_BINDING_REUSED'
    && MAD4B_SCP_Site_Profile::$commits===1,'subsequent profile save does not rotate key');
$GLOBALS['wp_hook']='wp_ajax_mad4b_site_profile_save';
assert_profile(MAD4B_SCP_Deployment_Binding_Native::profile_save_bootstrap()['state']==='EXISTING_BINDING_REUSED',
    'verified AJAX save path consistent');
@unlink($location['file']); @rmdir($location['dir']); @unlink($root.'/wp-load.php');
@rmdir($root); @rmdir($base);
echo "MAD4B_NATIVE_BINDING_PROFILE_SAVE_BOOTSTRAP: PASS\n";
