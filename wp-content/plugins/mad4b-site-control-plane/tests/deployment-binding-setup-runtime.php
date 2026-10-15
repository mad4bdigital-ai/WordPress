<?php
/* Exact Staging deployment binding setup: independent host key never in MCP. */
define( 'ABSPATH', __DIR__ );
class WP_Error {
    private $code;
    public function __construct( $code, $message = '' ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function wp_json_encode( $x, $flags = 0 ) { return json_encode( $x, $flags ); }
function add_action( $a, $b, $c = 10 ) {}
function do_action( $a ) {}
function get_option( $key, $fallback = null ) {
    return isset( $GLOBALS['profiles'][ $key ] ) ? $GLOBALS['profiles'][ $key ] : $fallback;
}
$GLOBALS['abilities'] = array();
function wp_has_ability( $a ) { return isset( $GLOBALS['abilities'][ $a ] ); }
function wp_register_ability( $a, $spec ) { $GLOBALS['abilities'][ $a ] = $spec; }
$GLOBALS['admin'] = true;
function current_user_can( $cap ) { return $GLOBALS['admin']; }
class MAD4B_SCP_Policy {
    public static function can_read() { return true; }
}
class MAD4B_SCP_Site_Profile_Enrollment {
    public static function can_execute( $input = null ) {
        return $GLOBALS['admin'] ? true : new WP_Error( 'permission_denied' );
    }
}
class MAD4B_SCP_Local_OAuth_Server { const CHATGPT_CIMD_CLIENT_ID = 'client-identity'; }
class MAD4B_SCP_OAuth_Resource_Bridge {
    public static function verified_bearer_client_is( $client ) { return true; }
}
class MAD4B_SCP_Authorization {
    public static $claims = 0;
    public static $fail = false;
    public static function authorize_mutation( $a, $s, $p, $input ) {
        return self::$fail ? new WP_Error( 'permission_denied' ) : true;
    }
    public static function permission_result_from_authorization( $x ) { return $x; }
    public static function claim_mutation( $a, $s, $p, $input ) {
        ++self::$claims;
        return self::$fail ? new WP_Error( 'ticket_denied' ) : array( 'allowed' => true );
    }
}
class MAD4B_SCP_Site_Profile {
    const OPTION = 'private-mad4b-site-profile-test';
    public static $host = '';
    public static $env = 'staging';
    public static $commits = 0;
    public static function deployment_binding_digest() { return self::$host; }
    public static function status() {
        $record = get_option( self::OPTION, array() );
        $bound = '' !== (string) ( $record['deployment_binding_digest'] ?? '' );
        return array( 'site_uuid' => (string) ( $record['site_uuid'] ?? '' ),
            'revision' => (int) ( $record['revision'] ?? 0 ),
            'profile_digest' => hash( 'sha256', json_encode( $record ) ),
            'configured_environment' => self::$env,
            'wordpress_environment' => self::$env,
            'canonical_origin' => (string) ( $record['canonical_origin'] ?? '' ),
            'origin_match' => true, 'environment_match' => true,
            'authority_ready' => true, 'mutation_pending_audit' => false,
            'deployment_binding_configured' => '' !== self::$host,
            'deployment_binding_bound' => $bound,
            'deployment_binding_match' => $bound
                ? hash_equals( (string) $record['deployment_binding_digest'], self::$host ) : true );
    }
    public static function commit_record_with_audit( $before, $after, $action, $audit, $prefix ) {
        if ( get_option( self::OPTION ) !== $before ) return new WP_Error( 'stale' );
        ++self::$commits;
        $GLOBALS['profiles'][self::OPTION] = $after;
        return array( 'revision' => $after['revision'], 'audit' => true );
    }
    public static function reset_cache() {}
}
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-deployment-binding-setup.php';
function bassert( $x, $why ) { if ( !$x ) { fwrite( STDERR, "FAIL: $why\n" ); exit( 1 ); } }
$GLOBALS['profiles'][MAD4B_SCP_Site_Profile::OPTION] = array(
    'site_uuid' => '49c562d1-8f2f-456f-b454-26816c6ba4cb',
    'canonical_origin' => 'https://staging.allroyalegypt.com',
    'environment' => 'staging', 'revision' => 3,
    'deployment_binding_digest' => '',
    'features' => array( 'oauth' => true, 'skills' => true,
        'write' => true, 'acceptance' => true, 'managed_runtime' => true ),
    'chatgpt_app_id' => 'private-existing-app',
    'oauth_user_ids' => array( 42 ), 'created_at' => '2026-10-01T00:00:00Z',
);
$before = $GLOBALS['profiles'][MAD4B_SCP_Site_Profile::OPTION];
MAD4B_SCP_Deployment_Binding_Setup::register_abilities();
bassert( count( $GLOBALS['abilities'] ) === 3, 'status, plan, apply registered separately' );
foreach ( $GLOBALS['abilities'] as $name => $ability ) {
    bassert( !$ability['meta']['mcp']['public']
        && $ability['input_schema']['additionalProperties'] === false,
        'no public arbitrary input or secret registration' );
}
$blocked = MAD4B_SCP_Deployment_Binding_Setup::status();
bassert( $blocked['state'] === 'HOST_SECRET_NOT_CONFIGURED'
    && !$blocked['host_secret_configured'] && !$blocked['ready_to_bind'],
    'host secret must originate outside WP database' );
$plan = MAD4B_SCP_Deployment_Binding_Setup::plan();
bassert( !$plan['eligible'] && $plan['plan_sha256'] === ''
    && !array_key_exists( 'host_binding_digest', $plan ),
    'no secret and no fake ready plan' );
$missing = MAD4B_SCP_Deployment_Binding_Setup::apply( array() );
bassert( is_wp_error( $missing ) && MAD4B_SCP_Site_Profile::$commits === 0,
    'inputless write never mutates' );
MAD4B_SCP_Site_Profile::$host = hash( 'sha256', 'test-private-host-root-not-a-real-secret' );
$ready = MAD4B_SCP_Deployment_Binding_Setup::plan();
bassert( $ready['eligible'] && strlen( $ready['plan_sha256'] ) === 64,
    'host secret digest enables exact reviewed plan only' );
$payload = array( 'expected_plan_sha256' => $ready['plan_sha256'],
    'expected_profile_digest' => $ready['expected_profile_digest'],
    'expected_revision' => $ready['expected_revision'],
    'expected_site_uuid' => $ready['expected_site_uuid'],
    'confirmation' => MAD4B_SCP_Deployment_Binding_Setup::CONFIRM,
    '_mad4b_approval_ticket_id' => 'd4bb34e4-8a10-4abd-8c21-a4592a4a7a33' );
$wrong = $payload;
$wrong['confirmation'] = 'YES';
bassert( is_wp_error( MAD4B_SCP_Deployment_Binding_Setup::apply( $wrong ) )
    && MAD4B_SCP_Site_Profile::$commits === 0,
    'incorrect owner confirmation fails before writes' );
$wrong = $payload; $wrong['expected_plan_sha256'] = str_repeat( 'a', 64 );
bassert( is_wp_error( MAD4B_SCP_Deployment_Binding_Setup::apply( $wrong ) )
    && MAD4B_SCP_Site_Profile::$commits === 0,
    'stale plan cannot bind host' );
MAD4B_SCP_Authorization::$fail = true;
bassert( is_wp_error( MAD4B_SCP_Deployment_Binding_Setup::apply( $payload ) )
    && MAD4B_SCP_Site_Profile::$commits === 0,
    'denied current owner approval blocks mutation' );
MAD4B_SCP_Authorization::$fail = false;
$GLOBALS['admin'] = false;
bassert( is_wp_error( MAD4B_SCP_Deployment_Binding_Setup::apply( $payload ) )
    && MAD4B_SCP_Site_Profile::$commits === 0,
    'nonadmin and foreign client cannot use owner write route' );
$GLOBALS['admin'] = true;
$done = MAD4B_SCP_Deployment_Binding_Setup::apply( $payload );
$after = $GLOBALS['profiles'][MAD4B_SCP_Site_Profile::OPTION];
bassert( !is_wp_error( $done ) && $done['binding_verified']
    && $done['state'] === 'BOUND_INDEPENDENT_READBACK_REQUIRED'
    && MAD4B_SCP_Site_Profile::$commits === 1,
    'valid enrolled step-up authorization can bind exact stored profile once' );
bassert( $after['revision'] === $before['revision'] + 1
    && $after['deployment_binding_digest'] === MAD4B_SCP_Site_Profile::$host
    && $after['site_uuid'] === $before['site_uuid']
    && $after['canonical_origin'] === $before['canonical_origin']
    && $after['features'] === $before['features']
    && $after['chatgpt_app_id'] === $before['chatgpt_app_id']
    && $after['oauth_user_ids'] === $before['oauth_user_ids']
    && $after['created_at'] === $before['created_at'],
    'full original identity and feature flags preserved byte-for-byte' );
bassert( strpos( json_encode( $done ), 'test-private-host-root-not-a-real-secret' ) === false,
    'never return or log raw key' );
$again = MAD4B_SCP_Deployment_Binding_Setup::status();
bassert( $again['state'] === 'ALREADY_BOUND'
    && !$again['ready_to_bind'], 'second attempt must not rotate or re-enroll' );
bassert( is_wp_error( MAD4B_SCP_Deployment_Binding_Setup::apply( $payload ) )
    && MAD4B_SCP_Site_Profile::$commits === 1,
    'replay with consumed approval and old plan cannot mutate' );
MAD4B_SCP_Site_Profile::$host = hash( 'sha256', 'different-secret' );
bassert( MAD4B_SCP_Deployment_Binding_Setup::status()['state']
    === 'STORED_HOST_BINDING_MISMATCH', 'rotation requires separate explicit contract' );
MAD4B_SCP_Site_Profile::$env = 'production';
bassert( MAD4B_SCP_Deployment_Binding_Setup::status()['state']
    === 'EXACT_STAGING_IDENTITY_BLOCKED', 'Production always denied' );
echo "mad4b.site-control-plane.deployment-binding-setup.runtime.v1: PASS\n";
