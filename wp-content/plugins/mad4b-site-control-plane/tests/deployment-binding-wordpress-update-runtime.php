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


$GLOBALS['wp_activation_hook'] = '';
$GLOBALS['wp_is_admin'] = true;
function current_filter() { return $GLOBALS['wp_activation_hook']; }
function is_admin() { return $GLOBALS['wp_is_admin']; }
class MAD4B_SCP_Live_Acceptance_Observer {
    public static $healthy = true;
    public static function build_provenance_status() {
        return array( 'runtime_manifest_match' => self::$healthy,
            'stale' => false, 'mismatch' => array(),
            'source_commit_sha' => str_repeat( 'a', 40 ) );
    }
}
function assert_update($yes,$why){if(!$yes){fwrite(STDERR,"FAIL:$why\n");exit(1);}}
$_SERVER['REQUEST_METHOD']='POST';
$GLOBALS['options'][MAD4B_SCP_Site_Profile::OPTION] = array(
    'site_uuid' => '49c562d1-8f2f-456f-b454-26816c6ba4cb',
    'environment' => 'staging',
    'canonical_origin' => 'https://staging.allroyalegypt.com',
    'revision' => 3, 'deployment_binding_digest' => '',
    'oauth_user_ids' => array(42),
    'features' => array('write'=>false,'skills'=>true),
);
$profile_before=get_option(MAD4B_SCP_Site_Profile::OPTION);
$location=MAD4B_SCP_Deployment_Binding_Native::private_location($profile_before['site_uuid']);
$method = new ReflectionMethod('MAD4B_SCP_Deployment_Binding_Native','wordpress_update_bootstrap');
$method->setAccessible(true);
$nonce = new ReflectionProperty('MAD4B_SCP_Deployment_Binding_Native','wordpress_setup_nonce_verified');
$nonce->setAccessible(true);
$invoke = static function() use ($method) {return $method->invoke(null);};
$GLOBALS['wp_activation_hook']='admin_post_mad4b_complete_staging_host_setup';
assert_update(is_wp_error($invoke()),'no signed WordPress request fails');
$nonce->setValue(null,true);
$_SERVER['REQUEST_METHOD']='GET';
assert_update(is_wp_error($invoke()),'GET with nonce marker cannot create');
$_SERVER['REQUEST_METHOD']='POST';
MAD4B_SCP_Live_Acceptance_Observer::$healthy=false;
assert_update(is_wp_error($invoke()),'tampered local package cannot create host key');
MAD4B_SCP_Live_Acceptance_Observer::$healthy=true;
MAD4B_SCP_Site_Profile::$env='production';
assert_update(is_wp_error($invoke()),'Production cannot acquire Staging host key');
MAD4B_SCP_Site_Profile::$env='staging';
$GLOBALS['wp_activation_hook']='upgrader_process_complete';
assert_update(is_wp_error($invoke()),'automatic upgrader hook cannot impersonate explicit admin POST');
$GLOBALS['wp_activation_hook']='admin_post_mad4b_complete_staging_host_setup';
$GLOBALS['admin']=false;
assert_update(is_wp_error($invoke()),'without privileges cannot create');
$GLOBALS['admin']=true;
$created=$invoke();
assert_update(is_array($created)&&'BOUND_NEW_REQUEST_READBACK_REQUIRED'===$created['state'],
    'explicit authenticated WordPress update completion creates one exact binding');
assert_update(MAD4B_SCP_Site_Profile::$commits===1,'one audited CAS commit');
$after=get_option(MAD4B_SCP_Site_Profile::OPTION);
assert_update($after['revision']===4 && $after['features']===$profile_before['features'],
    'profile revision advances without grants');
$reused=$invoke();
assert_update(is_array($reused)&&'EXISTING_BINDING_REUSED'===$reused['state']
    && MAD4B_SCP_Site_Profile::$commits===1,'safe retry does not rotate binding');
$nonce->setValue(null,false);
assert_update(is_wp_error($invoke()),'nonce proof never persisted across requests');
@unlink($location['file']); @rmdir($location['dir']); @unlink($root.'/wp-load.php');
@rmdir($root); @rmdir($base);
echo "MAD4B_WORDPRESS_NATIVE_POSTUPDATE_BOOTSTRAP: PASS\n";
