<?php
/** Route and admission regression; provider lifecycle is tested separately. */
require __DIR__ . '/provider-connection-center-runtime.php';
MAD4B_SCP_Site_Profile::$prod = false;
MAD4B_SCP_Google_Drive_Context::$status = array( 'connected' => true, 'refresh_required' => true, 'health_state' => 'refresh_required' );
$GLOBALS['registered_routes'] = array();
function register_rest_route( $ns, $path, $args ) { $GLOBALS['registered_routes'][$ns . $path] = $args; }
function rest_ensure_response( $value ) { return new ProviderRestResponse( $value ); }
class ProviderRestResponse {
    public $data; public $headers = array();
    public function __construct( $value ) { $this->data = $value; }
    public function header( $name, $value ) { $this->headers[$name] = $value; }
}
class ProviderRestRequest {
    private $provider; private $input;
    public function __construct( $provider, $input = array() ) { $this->provider = $provider; $this->input = $input; }
    public function get_param( $key ) { return 'provider' === $key ? $this->provider : null; }
    public function get_json_params() { return $this->input; }
    public function get_header( $key ) { return $GLOBALS['owner_nonce'] ?? ''; }
}
class MAD4B_SCP_Site_Profile_Enrollment { public static function can_execute( $value ) { return true; } }
class MAD4B_SCP_OAuth_Resource_Bridge {
    public static $verified = false;
    public static function verified_bearer_client_is( $client ) { return self::$verified; }
}
class MAD4B_SCP_Local_OAuth_Server { const CHATGPT_CIMD_CLIENT_ID = 'test-cimd-client'; }
class MAD4B_SCP_Authorization {
    public static function authorize_mutation( $ability, $server, $provider, $input ) { return true; }
    public static function permission_result_from_authorization( $value ) { return true; }
}
class FakeProviderAbility {
    public $calls = 0;
    public function execute( $input ) { $this->calls++; return array( 'state' => 'governed_ability_delegated', 'provider' => $input['provider'] ); }
}
$GLOBALS['provider_ability'] = new FakeProviderAbility();
function get_current_user_id() { return 1; }
function wp_verify_nonce( $nonce, $action ) { return $nonce === 'owner-browser-nonce' && $action === 'wp_rest'; }
function wp_get_ability( $name ) { return 'mad4b/provider-connection-refresh' === $name ? $GLOBALS['provider_ability'] : null; }
MAD4B_SCP_Provider_Connection_Center::register_rest_routes();
$routes = $GLOBALS['registered_routes'];
mad4b_assert( count( $routes ) === 4, 'Four bounded REST routes.' );
$base = 'mad4b/v1/provider-connections';
$discover = $routes[$base];
mad4b_assert( 'GET' === $discover['methods'], 'Discovery read-only.' );
$read = call_user_func( $discover['callback'], new ProviderRestRequest( null ) );
mad4b_assert( $read instanceof ProviderRestResponse && 'private, no-store' === $read->headers['Cache-Control'], 'Private discovery.' );
$plan = MAD4B_SCP_Provider_Connection_Center::plan( array( 'provider' => 'google_drive' ) );
$input = array( 'expected_plan_sha256' => $plan['plan_sha256'], 'expected_site_uuid' => $plan['expected_site_uuid'],
    'expected_profile_revision' => $plan['expected_profile_revision'], 'expected_profile_digest' => $plan['expected_profile_digest'],
    'confirmation' => $plan['confirmation'], '_mad4b_approval_ticket_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa' );
$refresh = $routes[$base . '/(?P<provider>[a-z][a-z0-9_-]{0,63})/refresh'];
$missing = $input; unset( $missing['_mad4b_approval_ticket_id'] );
mad4b_assert( is_wp_error( call_user_func( $refresh['permission_callback'], new ProviderRestRequest( 'google_drive', $missing ) ) ), 'Missing approval rejected.' );
mad4b_assert( is_wp_error( call_user_func( $refresh['permission_callback'], new ProviderRestRequest( 'google_drive', $input ) ) ), 'Unverified OAuth rejected.' );
MAD4B_SCP_OAuth_Resource_Bridge::$verified = true;
mad4b_assert( true === call_user_func( $refresh['permission_callback'], new ProviderRestRequest( 'google_drive', $input ) ), 'Verified owner with exact payload admitted.' );
$result = call_user_func( $refresh['callback'], new ProviderRestRequest( 'google_drive', $input ) );
mad4b_assert( $result instanceof ProviderRestResponse && $result->data['state'] === 'governed_ability_delegated'
    && $GLOBALS['provider_ability']->calls === 1, 'REST delegates to existing governed ability only.' );
mad4b_assert( is_wp_error( call_user_func( $refresh['callback'], new ProviderRestRequest( 'google_drive', $missing ) ) ), 'Direct callback also validates.' );
MAD4B_SCP_Site_Profile::$prod = true;
mad4b_assert( is_wp_error( call_user_func( $refresh['permission_callback'], new ProviderRestRequest( 'google_drive', $input ) ) ), 'Production fail closed.' );

// Reconnect requires exact Staging CAS and same-browser owner REST nonce.
MAD4B_SCP_Site_Profile::$prod = false;
MAD4B_SCP_Google_Drive_Context::$status = array( 'connected' => true, 'refresh_required' => true,
    'reconnect_required' => true, 'refresh_failed' => true, 'health_state' => 'refresh_failed' );
$GLOBALS['owner_nonce'] = '';
$reconnect = $routes[$base . '/(?P<provider>[a-z][a-z0-9_-]{0,63})/reconnect'];
$next = MAD4B_SCP_Provider_Connection_Center::plan( array( 'provider' => 'google_drive' ) );
mad4b_assert( $next['action'] === 'oauth_handoff' && $next['reconnect_rest_available'], 'Same-grant reconnect plan available.' );
$reconnect_input = array( 'expected_plan_sha256' => $next['plan_sha256'],
    'expected_site_uuid' => $next['expected_site_uuid'],
    'expected_profile_revision' => $next['expected_profile_revision'],
    'expected_profile_digest' => $next['expected_profile_digest'],
    'confirmation' => $next['confirmation'] );
mad4b_assert( is_wp_error( call_user_func( $reconnect['permission_callback'], new ProviderRestRequest( 'google_drive', $reconnect_input ) ) ), 'Nonce-free reconnect denied.' );
$GLOBALS['owner_nonce'] = 'owner-browser-nonce';
mad4b_assert( true === call_user_func( $reconnect['permission_callback'], new ProviderRestRequest( 'google_drive', $reconnect_input ) ), 'Exact owner reconnect permitted.' );
$stale = $reconnect_input; $stale['expected_plan_sha256'] = str_repeat( 'f', 64 );
mad4b_assert( is_wp_error( call_user_func( $reconnect['permission_callback'], new ProviderRestRequest( 'google_drive', $stale ) ) ), 'Stale grant identity denied.' );
MAD4B_SCP_Google_Drive_Context::$requested_scope = 'new-ungranted-scope';
mad4b_assert( is_wp_error( call_user_func( $reconnect['permission_callback'], new ProviderRestRequest( 'google_drive', $reconnect_input ) ) ), 'Unreviewed new scope denied.' );
MAD4B_SCP_Google_Drive_Context::$requested_scope = 'scope-read';
$start = call_user_func( $reconnect['callback'], new ProviderRestRequest( 'google_drive', $reconnect_input ) );
mad4b_assert( $start instanceof ProviderRestResponse && $start->data['state'] === 'owner_consent_pending'
    && $start->data['credential_replaced'] === false && $start->data['scopes_expanded'] === false
    && MAD4B_SCP_Google_Drive_Context::$auth_calls === 1, 'Native OAuth handshake begun without replacing token.' );
MAD4B_SCP_Google_Drive_Context::$status['revocation_pending'] = true;
mad4b_assert( is_wp_error( call_user_func( $reconnect['callback'], new ProviderRestRequest( 'google_drive', $reconnect_input ) ) ), 'Pending revocation blocks reconnect.' );
MAD4B_SCP_Google_Drive_Context::$status['revocation_pending'] = false;
MAD4B_SCP_Site_Profile::$prod = true;
mad4b_assert( is_wp_error( call_user_func( $reconnect['callback'], new ProviderRestRequest( 'google_drive', $reconnect_input ) ) ), 'Production reconnect denied.' );
echo "mad4b.provider-connection-rest-routing.runtime: PASS\n";
