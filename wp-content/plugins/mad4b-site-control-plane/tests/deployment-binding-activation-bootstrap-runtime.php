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
$GLOBALS['wp_activation_hook'] = '';
$GLOBALS['wp_is_admin'] = true;
function current_filter() { return $GLOBALS['wp_activation_hook']; }
function plugin_basename( $file ) { return $file; }
function is_admin() { return $GLOBALS['wp_is_admin']; }
function check_bootstrap( $passed, $case ) {
    if ( ! $passed ) { fwrite( STDERR, "FAIL activation: $case\n" ); exit( 1 ); }
}
$GLOBALS['options'][ MAD4B_SCP_Site_Profile::OPTION ] = array(
    'site_uuid' => '49c562d1-8f2f-456f-b454-26816c6ba4cb',
    'environment' => 'staging',
    'canonical_origin' => 'https://staging.allroyalegypt.com',
    'revision' => 3, 'deployment_binding_digest' => '',
    'oauth_user_ids' => array( 42 ), 'features' => array( 'write' => true, 'skills' => true ),
);
$profile_before = get_option( MAD4B_SCP_Site_Profile::OPTION );
$location = MAD4B_SCP_Deployment_Binding_Native::private_location( $profile_before['site_uuid'] );
check_bootstrap( ! empty( $location ) && ! file_exists( $location['dir'] ), 'safe initial path' );
$untrusted = MAD4B_SCP_Deployment_Binding_Native::activation_bootstrap();
check_bootstrap( is_wp_error( $untrusted )
    && 'mad4b_native_binding_activation_context_required' === $untrusted->get_error_code(),
    'ordinary requests cannot mint identity' );
$GLOBALS['wp_activation_hook'] = 'upgrader_process_complete';
check_bootstrap( is_wp_error( MAD4B_SCP_Deployment_Binding_Native::activation_bootstrap() ),
    'update callback cannot impersonate admin activation' );
$GLOBALS['wp_activation_hook'] = 'activate_mad4b-site-control-plane/mad4b-site-control-plane.php';
$GLOBALS['admin'] = false;
check_bootstrap( is_wp_error( MAD4B_SCP_Deployment_Binding_Native::activation_bootstrap() ),
    'non-admin activation cannot mint identity' );
$GLOBALS['admin'] = true;
MAD4B_SCP_Site_Profile::$env = 'production';
check_bootstrap( is_wp_error( MAD4B_SCP_Deployment_Binding_Native::activation_bootstrap() ),
    'production cannot auto-create host key' );
MAD4B_SCP_Site_Profile::$env = 'staging';
MAD4B_SCP_Site_Profile::$fail_commit = true;
$rollback = MAD4B_SCP_Deployment_Binding_Native::activation_bootstrap();
check_bootstrap( is_wp_error( $rollback )
    && ! file_exists( $location['dir'] ) && ! file_exists( $location['file'] )
    && MAD4B_SCP_Site_Profile::$commits === 0,
    'audit DB CAS failure cleans private host directory' );
MAD4B_SCP_Site_Profile::$fail_commit = false;
$bound = MAD4B_SCP_Deployment_Binding_Native::activation_bootstrap();
$profile_after = get_option( MAD4B_SCP_Site_Profile::OPTION );
check_bootstrap( is_array( $bound ) && 'BOUND_NEW_REQUEST_READBACK_REQUIRED' === $bound['state']
    && ! empty( $bound['activation_bootstrap'] ) && MAD4B_SCP_Site_Profile::$commits === 1,
    'real admin plugin activation creates one audited host binding' );
check_bootstrap( $profile_after['site_uuid'] === $profile_before['site_uuid']
    && $profile_after['canonical_origin'] === $profile_before['canonical_origin']
    && $profile_after['revision'] === $profile_before['revision'] + 1
    && $profile_after['oauth_user_ids'] === $profile_before['oauth_user_ids'],
    'activation preserves identity and grants' );
check_bootstrap( is_file( $location['file'] )
    && ( fileperms( $location['file'] ) & 0077 ) === 0
    && ( fileperms( $location['dir'] ) & 0077 ) === 0, 'host key private permissions' );
$secret = MAD4B_SCP_Deployment_Binding_Native::read_host_secret( $profile_before['site_uuid'] );
check_bootstrap( strlen( $secret ) === 96
    && hash_equals( hash( 'sha256', $secret ), $profile_after['deployment_binding_digest'] )
    && strpos( json_encode( $bound ), $secret ) === false, 'secret never disclosed' );
$repeat = MAD4B_SCP_Deployment_Binding_Native::activation_bootstrap();
check_bootstrap( is_array( $repeat ) && $repeat['state'] === 'EXISTING_BINDING_REUSED'
    && MAD4B_SCP_Site_Profile::$commits === 1, 'reactivation reuses key without more writes' );
$bad_clone = $profile_after;
$bad_clone['deployment_binding_digest'] = str_repeat( 'a', 64 );
$GLOBALS['options'][MAD4B_SCP_Site_Profile::OPTION] = $bad_clone;
check_bootstrap( is_wp_error( MAD4B_SCP_Deployment_Binding_Native::activation_bootstrap() )
    && file_get_contents( $location['file'] ) === $secret
    && MAD4B_SCP_Site_Profile::$commits === 1, 'copied or mismatched profile does not rotate identity' );
$GLOBALS['options'][MAD4B_SCP_Site_Profile::OPTION] = $profile_after;
$same = MAD4B_SCP_Deployment_Binding_Native::readback( array(
    'expected_site_uuid' => $profile_after['site_uuid'],
    'minimum_profile_revision' => $profile_after['revision'],
) );
check_bootstrap( !$same['independent_request_observed'], 'activation is not independent readback' );
$marker = new ReflectionProperty( 'MAD4B_SCP_Deployment_Binding_Native', 'request_marker' );
$marker->setAccessible( true );
$marker->setValue( null, '' );
$independent = MAD4B_SCP_Deployment_Binding_Native::readback( array(
    'expected_site_uuid' => $profile_after['site_uuid'],
    'minimum_profile_revision' => $profile_after['revision'],
) );
check_bootstrap( $independent['state'] === 'WORDPRESS_BINDING_READBACK_PASS'
    && $independent['independent_request_observed'], 'next request readback verified' );
@unlink($location['file']); @rmdir($location['dir']); @unlink($root.'/wp-load.php');
@rmdir($root); @rmdir($base);
echo "MAD4B_NATIVE_BINDING_ACTIVATION_BOOTSTRAP: PASS\n";
