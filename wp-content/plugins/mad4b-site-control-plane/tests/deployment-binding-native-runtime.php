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
    public static function authorize_mutation( $name, $surface, $provider, $input ) {
        return self::$approved ? true : new WP_Error( 'not_approved' );
    }
    public static function permission_result_from_authorization( $decision ) { return $decision; }
    public static function claim_mutation( $name, $surface, $provider, $input ) {
        self::$claimed++;
        return self::$approved ? array( 'allowed' => true ) : new WP_Error( 'not_claimed' );
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
function bn_assert( $ok, $label ) {
    if ( ! $ok ) { fwrite( STDERR, "FAIL: $label\n" ); exit( 1 ); }
}
$GLOBALS['options'][MAD4B_SCP_Site_Profile::OPTION] = array(
    'site_uuid' => '49c562d1-8f2f-456f-b454-26816c6ba4cb',
    'environment' => 'staging',
    'canonical_origin' => 'https://staging.allroyalegypt.com',
    'revision' => 3, 'deployment_binding_digest' => '',
    'oauth_user_ids' => array( 42 ), 'features' => array( 'write' => true, 'skills' => true ),
);
$original = $GLOBALS['options'][MAD4B_SCP_Site_Profile::OPTION];
MAD4B_SCP_Deployment_Binding_Native::register_abilities();
bn_assert( count( $GLOBALS['abilities'] ) === 3, 'exactly status plan apply' );
foreach ($GLOBALS['abilities'] as $name => $spec) {
    bn_assert( !$spec['meta']['mcp']['public']
        && !$spec['input_schema']['additionalProperties']
        && ( $name !== MAD4B_SCP_Deployment_Binding_Native::APPLY
            || !$spec['meta']['annotations']['readonly'] ),
        'no public or arbitrary URL/filesystem inputs' );
}
$s = MAD4B_SCP_Deployment_Binding_Native::status();
bn_assert( $s['eligible'] && $s['state'] === 'READY_FOR_OWNER_APPROVAL',
    'Staging PHP can provision outside its document root' );
$p = MAD4B_SCP_Deployment_Binding_Native::plan();
bn_assert( $p['eligible'] && strlen($p['plan_sha256']) === 64
    && $p['no_ssh_or_wp_config_edit'] && !array_key_exists('secret',$p),
    'one approval exact plan never returns secret' );
$payload = array(
    'expected_plan_sha256' => $p['plan_sha256'],
    'expected_profile_digest' => $p['expected_profile_digest'],
    'expected_revision' => $p['expected_revision'],
    'expected_site_uuid' => $p['expected_site_uuid'],
    'confirmation' => MAD4B_SCP_Deployment_Binding_Native::CONFIRM,
    '_mad4b_approval_ticket_id' => 'd4bb34e4-8a10-4abd-8c21-a4592a4a7a33',
);
$wrong = $payload; $wrong['confirmation'] = 'YES';
bn_assert( is_wp_error(MAD4B_SCP_Deployment_Binding_Native::apply($wrong))
    && MAD4B_SCP_Authorization::$claimed === 0, 'wrong confirmation denied before claim' );
$wrong = $payload; $wrong['expected_plan_sha256'] = str_repeat('a',64);
bn_assert( is_wp_error(MAD4B_SCP_Deployment_Binding_Native::apply($wrong))
    && MAD4B_SCP_Authorization::$claimed === 0, 'stale plan denied' );
MAD4B_SCP_Authorization::$approved = false;
bn_assert( is_wp_error(MAD4B_SCP_Deployment_Binding_Native::apply($payload))
    && MAD4B_SCP_Site_Profile::$commits === 0, 'explicit owner approval mandatory' );
MAD4B_SCP_Authorization::$approved = true;
$location = MAD4B_SCP_Deployment_Binding_Native::private_location($original['site_uuid']);
bn_assert( !empty($location) && dirname($location['dir']) === realpath($base),
    'private folder fixed outside site root' );
MAD4B_SCP_Site_Profile::$fail_commit = true;
bn_assert( is_wp_error(MAD4B_SCP_Deployment_Binding_Native::apply($payload))
    && !file_exists($location['file']) && !file_exists($location['dir'])
    && MAD4B_SCP_Site_Profile::$commits === 0,
    'DB audit CAS failure compensates creation of exact token' );
MAD4B_SCP_Site_Profile::$fail_commit = false;
$out = MAD4B_SCP_Deployment_Binding_Native::apply($payload);
$updated = get_option(MAD4B_SCP_Site_Profile::OPTION);
bn_assert( is_array($out) && $out['state'] === 'BOUND_NEW_REQUEST_READBACK_REQUIRED'
    && MAD4B_SCP_Site_Profile::$commits === 1,
    'single approved WordPress native transaction creates and binds key' );
bn_assert( $updated['site_uuid'] === $original['site_uuid']
    && $updated['canonical_origin'] === $original['canonical_origin']
    && $updated['features'] === $original['features']
    && $updated['oauth_user_ids'] === $original['oauth_user_ids']
    && $updated['revision'] === $original['revision']+1,
    'identity and enrolled scope preserved' );
bn_assert( file_exists($location['file']) && !is_link($location['file'])
    && (fileperms($location['file']) & 0077) === 0
    && (fileperms($location['dir']) & 0077) === 0,
    'private key never public and has restricted permissions' );
$key = MAD4B_SCP_Deployment_Binding_Native::read_host_secret($original['site_uuid']);
bn_assert( preg_match('/^[a-f0-9]{96}$/D',$key)
    && hash_equals($updated['deployment_binding_digest'],hash('sha256',$key))
    && strpos(json_encode($out),$key) === false,
    'key is only host-local and only fingerprint stored in WP' );
bn_assert( MAD4B_SCP_Deployment_Binding_Native::status()['state'] === 'ALREADY_HAS_PROFILE_BINDING'
    && is_wp_error(MAD4B_SCP_Deployment_Binding_Native::apply($payload)),
    'no replay or rotation' );
$_SERVER['DOCUMENT_ROOT'] = $base; // parent is now the public web root
bn_assert( MAD4B_SCP_Deployment_Binding_Native::private_location($original['site_uuid']) === array(),
    'reject private folder under an expanded document root' );
$_SERVER['DOCUMENT_ROOT'] = $root;
MAD4B_SCP_Site_Profile::$env = 'production';
bn_assert( MAD4B_SCP_Deployment_Binding_Native::status()['state'] === 'SAFE_LOCATION_UNAVAILABLE',
    'Production fail closed even if host-local key exists' );
@unlink($location['file']); @rmdir($location['dir']); @unlink($root.'/wp-load.php');
@rmdir($root); @rmdir($base);
echo "mad4b.site-control-plane.deployment-binding-native.runtime.v1: PASS\n";
