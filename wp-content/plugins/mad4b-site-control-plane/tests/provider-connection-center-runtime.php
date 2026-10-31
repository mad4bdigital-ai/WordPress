<?php
/** Provider-neutral health/plan + fail-closed remote mutation regression. */
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );
class WP_Error {
    private $code;
    public function __construct( $code, $message = '' ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function add_action( $hook, $callback, $priority = 10 ) {}
function apply_filters( $name, $base ) { return $base; }
$GLOBALS['provider_ci_admin'] = true;
function current_user_can( $cap ) { return 'manage_options' === $cap && ! empty( $GLOBALS['provider_ci_admin'] ); }
function admin_url( $path = '' ) { return 'https://staging.example.test/wp-admin/' . $path; }
function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $s ) ); }
function sanitize_text_field( $s ) { return trim( (string) $s ); }
function wp_json_encode( $x, $flags = 0 ) { return json_encode( $x, $flags ); }
function wp_unslash( $s ) { return $s; }
function wp_validate_redirect( $s, $fallback = '' ) { return $s; }
function mad4b_assert( $test, $what ) {
    if ( ! $test ) { fwrite( STDERR, "FAIL: " . $what . "\n" ); exit( 1 ); }
}
final class MAD4B_SCP_Policy { public static function can_read() { return true; } }
final class MAD4B_SCP_Site_Profile {
    public static $prod = false;
    public static function status() {
        return array( 'site_uuid' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            'revision' => 3, 'profile_digest' => str_repeat( 'b', 64 ),
            'configured_environment' => self::$prod ? 'production' : 'staging',
            'wordpress_environment' => self::$prod ? 'production' : 'staging',
            'origin_match' => true, 'environment_match' => true, 'authority_ready' => true );
    }
}
final class MAD4B_SCP_Google_Drive_Context {
    public static $status = array();
    public static $refresh_calls = 0;
    const AUTH_MODE_MANAGED = 'managed';
    public static $auth_calls = 0;
    public static $requested_scope = 'scope-read';
    public static function auth_mode() { return 'custom'; }
    public static function connection_status() { return array_merge( self::$status, array( 'scope' => 'scope-read', 'token_unreadable' => false ) ); }
    public static function workspace_grants_status() { return array( 'scopes' => array( self::$requested_scope ), 'selection' => array( 'drive' => 'read' ), 'managed_profile_reconsent_required' => false ); }
    public static function authorization_url( $access_mode ) { self::$auth_calls++; return $access_mode === 'read_only' ? 'https://accounts.google.com/o/oauth2/v2/auth?state=test' : new WP_Error( 'bad_mode' ); }
    public static function managed_authorization_url( $access_mode ) { return new WP_Error( 'managed_not_configured' ); }
    public static function public_connection_status() { return self::$status; }
    public static function encrypted_grant_identity_sha256() { return str_repeat( 'c', 64 ); }
    public static function refresh_existing_connection( $expected ) {
        self::$refresh_calls++;
        return array( 'state' => 'refreshed' );
    }
}
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-provider-connection-center.php';
$center = 'MAD4B_SCP_Provider_Connection_Center';
MAD4B_SCP_Google_Drive_Context::$status = array(
    'connected' => true, 'health_state' => 'refresh_required',
    'refresh_required' => true, 'credential_fresh' => false );
$discovery = $center::discover();
mad4b_assert( ! is_wp_error( $discovery ) && 1 === $discovery['count']
    && 'google_drive' === $discovery['providers'][0]['provider']
    && $discovery['providers'][0]['remote_refresh_available'],
    'Google registered and discovery safe.' );
$plan = $center::plan( array( 'provider' => 'google_drive' ) );
mad4b_assert( ! is_wp_error( $plan ) && 'refresh' === $plan['action']
    && $plan['eligible_remote_refresh']
    && 64 === strlen( $plan['plan_sha256'] ) && $plan['one_time_approval_required_for_remote_refresh'],
    'Exact saved-grant refresh plan with owner approval.' );
mad4b_assert( is_wp_error( $center::refresh( array( 'provider' => 'google_drive' ) ) ),
    'Remote refresh must deny missing one-time approval and exact bound plan.' );
mad4b_assert( 0 === MAD4B_SCP_Google_Drive_Context::$refresh_calls,
    'No Google mutation when approval is absent.' );
MAD4B_SCP_Google_Drive_Context::$status['revocation_pending'] = true;
$pending = $center::plan( array( 'provider' => 'google_drive' ) );
mad4b_assert( 'resolve_pending_revocation' === $pending['action'] && ! $pending['eligible_remote_refresh'],
    'Pending revoke is not a refreshable token.' );
MAD4B_SCP_Google_Drive_Context::$status['revocation_pending'] = false;
MAD4B_SCP_Google_Drive_Context::$status['refresh_required'] = false;
MAD4B_SCP_Google_Drive_Context::$status['reconnect_required'] = true;
MAD4B_SCP_Google_Drive_Context::$status['health_state'] = 'refresh_failed';
$handoff = $center::plan( array( 'provider' => 'google_drive' ) );
mad4b_assert( 'oauth_handoff' === $handoff['action']
    && $handoff['consent_requires_browser']
    && false !== strpos( $handoff['owner_handoff_url'], '/wp-admin/' ),
    'Invalid grant returns same-origin owner OAuth handoff, never silent consent.' );
MAD4B_SCP_Site_Profile::$prod = true;
$prod = $center::plan( array( 'provider' => 'google_drive' ) );
mad4b_assert( empty( $prod['eligible_remote_refresh'] ), 'Never offer approved remote refresh on Production.' );
// The REST adapter must not grant API-key/cookie users a fresh provider
// mutation; registration, permissions and browser nonce are separately gated.
$GLOBALS['provider_ci_routes'] = array();
function register_rest_route( $namespace, $route, $args ) {
    $GLOBALS['provider_ci_routes'][ $namespace . $route ] = $args;
}
function get_current_user_id() { return 7; }
function wp_verify_nonce( $value, $action ) {
    return 'wp_rest' === $action && 'fixture-owner-nonce' === $value;
}
final class MAD4B_Provider_CI_Request {
    private $provider, $body, $nonce;
    public function __construct( $provider, $body, $nonce = '' ) {
        $this->provider = $provider;
        $this->body = $body;
        $this->nonce = $nonce;
    }
    public function get_param( $name ) { return 'provider' === $name ? $this->provider : null; }
    public function get_json_params() { return $this->body; }
    public function get_header( $name ) { return 'X-WP-Nonce' === $name ? $this->nonce : ''; }
}
$center::register_rest_routes();
$routes = $GLOBALS['provider_ci_routes'];
$prefix = 'mad4b/v1/provider-connections';
$dynamic = '/(?P<provider>[a-z][a-z0-9_-]{0,63})';
mad4b_assert( 4 === count( $routes ), 'Exactly four explicit provider routes' );
mad4b_assert( 'GET' === $routes[$prefix]['methods']
    && $routes[$prefix]['permission_callback'][1] === 'rest_read_permission'
    && 'GET' === $routes[$prefix . $dynamic . '/plan']['methods']
    && $routes[$prefix . $dynamic . '/plan']['permission_callback'][1] === 'rest_read_permission',
    'Provider discovery and planning remain admin-gated reads' );
mad4b_assert( 'POST' === $routes[$prefix . $dynamic . '/refresh']['methods']
    && $routes[$prefix . $dynamic . '/refresh']['permission_callback'][1] === 'rest_refresh_permission'
    && 'POST' === $routes[$prefix . $dynamic . '/reconnect']['methods']
    && $routes[$prefix . $dynamic . '/reconnect']['permission_callback'][1] === 'rest_reconnect_permission',
    'Refresh and browser reconnect use distinct mutation admission' );
$GLOBALS['provider_ci_admin'] = false;
$denied = $center::rest_read_permission();
mad4b_assert( is_wp_error( $denied ) && 'mad4b_provider_rest_read_denied' === $denied->get_error_code(),
    'REST reads deny non-administrator' );
$denied = $center::rest_refresh_permission( new MAD4B_Provider_CI_Request( 'google_drive', array() ) );
mad4b_assert( is_wp_error( $denied ) && 'mad4b_provider_rest_owner_denied' === $denied->get_error_code(),
    'REST token refresh denies non-administrator before payload' );
$GLOBALS['provider_ci_admin'] = true;
$denied = $center::rest_refresh_permission( new MAD4B_Provider_CI_Request( 'google_drive',
    array( 'expected_plan_sha256' => str_repeat( 'a', 64 ) ) ) );
mad4b_assert( is_wp_error( $denied ) && 'mad4b_provider_rest_exact_payload_required' === $denied->get_error_code(),
    'REST token refresh denies incomplete one-time ticket' );
$reconnect = new MAD4B_Provider_CI_Request( 'google_drive', array() );
$denied = $center::rest_reconnect_permission( $reconnect );
mad4b_assert( is_wp_error( $denied ) && 'mad4b_provider_reconnect_browser_nonce_required' === $denied->get_error_code(),
    'REST reconnect denies absent browser nonce' );
$denied = $center::rest_reconnect_permission( new MAD4B_Provider_CI_Request( 'google_drive',
    array(), 'fixture-owner-nonce' ) );
mad4b_assert( is_wp_error( $denied ) && 'mad4b_provider_reconnect_exact_plan_required' === $denied->get_error_code(),
    'REST reconnect denies valid nonce without bound exact plan' );
mad4b_assert( 0 === MAD4B_SCP_Google_Drive_Context::$auth_calls
    && 0 === MAD4B_SCP_Google_Drive_Context::$refresh_calls,
    'Denied REST operations never trigger OAuth consent or token mutation' );

echo "PASS: provider connection discover/plan, pending/reconnect separation and denied remote mutation\n";
