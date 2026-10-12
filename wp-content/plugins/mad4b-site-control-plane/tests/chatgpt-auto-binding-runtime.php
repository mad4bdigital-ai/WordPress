<?php
/** Pure WordPress mocked runtime: no network, no secrets, no plugin mutation. */
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['bind_opt'] = array();
$GLOBALS['bind_writes'] = 0;
$GLOBALS['bind_profile'] = true;
$GLOBALS['bind_portable'] = false;
$GLOBALS['bind_oauth_feature'] = true;
$GLOBALS['bind_admin'] = true;
$GLOBALS['bind_user'] = 7;
$GLOBALS['bind_current_user'] = 7;
$GLOBALS['bind_bearer'] = false;
$GLOBALS['bind_client'] = false;
$GLOBALS['bind_scope'] = false;
$GLOBALS['bind_uuid'] = '123e4567-e89b-42d3-a456-426614174000';
$GLOBALS['bind_digest'] = str_repeat( 'a', 64 );
function assert_bind( $ok, $msg ) {
    if ( ! $ok ) { fwrite( STDERR, "FAIL: " . $msg . "\n" ); exit( 1 ); }
}
function absint( $v ) { return abs( (int) $v ); }
function untrailingslashit( $s ) { return rtrim( (string) $s, '/\\' ); }
function get_option( $name, $default = false ) {
    return array_key_exists( $name, $GLOBALS['bind_opt'] ) ? $GLOBALS['bind_opt'][ $name ] : $default;
}
function update_option( $name, $value, $autoload = null ) {
    $GLOBALS['bind_writes']++;
    $GLOBALS['bind_opt'][ $name ] = $value;
    return true;
}
function get_current_user_id() { return (int) $GLOBALS['bind_current_user']; }
function get_userdata( $user_id ) { return $user_id === 7 ? (object) array( 'ID' => 7 ) : false; }
function user_can( $user, $capability ) {
    return $user && $capability === 'manage_options' && ! empty( $GLOBALS['bind_admin'] );
}
class MAD4B_SCP_Site_Profile {
    public static function origin_enrolled() { return ! empty( $GLOBALS['bind_profile'] ); }
    public static function site_urls_match_enrollment() { return ! empty( $GLOBALS['bind_profile'] ); }
    public static function oauth_enabled() { return ! empty( $GLOBALS['bind_oauth_feature'] ); }
    public static function user_is_enrolled( $id ) { return (int) $id === (int) $GLOBALS['bind_user']; }
    public static function current_environment() { return 'staging'; }
    public static function site_uuid() { return $GLOBALS['bind_uuid']; }
    public static function profile_digest() { return $GLOBALS['bind_digest']; }
    public static function site_origin() { return 'https://staging.example.test'; }
}
class MAD4B_SCP_Portable_Readonly_Connection {
    public static function effective() { return !empty( $GLOBALS['bind_portable'] ); }
    public static function status() { return array(
        'environment' => 'staging', 'origin' => 'https://staging.example.test' ); }
    public static function user_is_enrolled( $id ) { return $id === 7; }
    public static function connection_uuid() { return '123e4567-e89b-52d3-a456-426614174000'; }
    public static function connection_digest() { return str_repeat( 'c', 64 ); }
}
class MAD4B_SCP_OAuth_Resource_Bridge {
    public static function resource_identifier() { return 'https://staging.example.test/wp-json/mcp/mad4b-chatgpt'; }
    public static function verified_bearer_active() { return !empty( $GLOBALS['bind_bearer'] ); }
    public static function verified_bearer_client_is( $client_id ) {
        return !empty( $GLOBALS['bind_bearer'] ) && !empty( $GLOBALS['bind_client'] ) &&
            $client_id === MAD4B_SCP_ChatGPT_Auto_Binding::CHATGPT_CLIENT_ID;
    }
    public static function verified_bearer_has_scope( $scope ) {
        return !empty( $GLOBALS['bind_bearer'] ) && !empty( $GLOBALS['bind_scope'] ) &&
            $scope === 'mad4b:read';
    }
}
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-chatgpt-auto-binding.php';
$c = 'MAD4B_SCP_ChatGPT_Auto_Binding';
$client = $c::CHATGPT_CLIENT_ID;
$resource = MAD4B_SCP_OAuth_Resource_Bridge::resource_identifier();
assert_bind( $c::status()['state'] === 'unbound', 'No connection is fabricated at plugin install.' );
assert_bind( ! $c::observe_local_token_issued( 'https://other.example/client', 7, $resource, array( 'mad4b:read' ) ),
    'Foreign OAuth clients cannot be auto-bound.' );
assert_bind( ! $c::observe_local_token_issued( $client, 7, $resource . '/foreign', array( 'mad4b:read' ) ),
    'Wrong MCP resource cannot be associated.' );
assert_bind( ! $c::observe_local_token_issued( $client, 7, $resource, array( 'offline_access' ) ),
    'Missing read scope cannot be associated.' );
assert_bind( ! $c::observe_local_token_issued( $client, 8, $resource, array( 'mad4b:read' ) ),
    'Foreign WordPress user cannot be enrolled.' );
assert_bind( $GLOBALS['bind_writes'] === 0, 'Untrusted auth cannot mutate a connection record.' );
assert_bind( $c::observe_local_token_issued( $client, 7, $resource, array( 'mad4b:read' ) ),
    'Real local token issuance is auto-bound.' );
$s = $c::status();
assert_bind( $s['client_observed'] && $s['state'] === 'bound' &&
    !$s['chatgpt_app_id_disclosed_by_oauth'] && !$s['app_id_invented_or_stored'] &&
    !$s['write_grants_created'] && !$s['production_write_authorized'] &&
    !$s['independent_mcp_session_certified'], 'Bound OAuth client is not an App ID or authority grant.' );
assert_bind( $GLOBALS['bind_writes'] === 1, 'One durable identity observation created.' );
assert_bind( $c::observe_local_token_issued( $client, 7, $resource, array( 'mad4b:read' ) ) &&
    $GLOBALS['bind_writes'] === 1, 'Repeated token refresh does not write every request.' );
assert_bind( !$c::current_bearer_binding_ready(), 'Persisted metadata cannot replace a current verified bearer.' );
$GLOBALS['bind_bearer'] = true;
$GLOBALS['bind_client'] = true;
$GLOBALS['bind_scope'] = true;
assert_bind( $c::current_bearer_binding_ready(), 'Current exact OAuth client can be dynamically identified.' );
$GLOBALS['bind_current_user'] = 8;
assert_bind( !$c::current_bearer_binding_ready(), 'Another logged-in account cannot borrow a stored OAuth connection binding.' );
$GLOBALS['bind_current_user'] = 7;
$GLOBALS['bind_client'] = false;
assert_bind( !$c::current_bearer_binding_ready(), 'Same site bearer from another OAuth client is not authorized.' );
$GLOBALS['bind_client'] = true;
$GLOBALS['bind_digest'] = str_repeat( 'b', 64 );
assert_bind( !$c::status()['client_observed'] && !$c::current_bearer_binding_ready(),
    'Profile digest changes invalidate the cached observation.' );
assert_bind( $c::observe_verified_mcp_request( $resource, 7 ) && $c::current_bearer_binding_ready(),
    'First verified MCP call rebinds an existing connected account automatically.' );
$GLOBALS['bind_profile'] = false;
assert_bind( !$c::status()['client_observed'] &&
    !$c::observe_verified_mcp_request( $resource, 7 ),
    'Unknown or foreign Site Profile remains fail closed.' );
$GLOBALS['bind_profile'] = true;
$GLOBALS['bind_opt'][ $c::OPTION ]['observed_at_unix'] = time() - $c::MAX_AGE - 1;
assert_bind( !$c::status()['client_observed'], 'Expired evidence cannot impersonate a current binding.' );
$GLOBALS['bind_admin'] = false;
assert_bind( !$c::observe_verified_mcp_request( $resource, 7 ), 'Revoked administrator cannot update connection identity.' );
assert_bind( !$c::current_bearer_binding_ready(), 'Revoked administrator cannot claim auto-binding.' );
$GLOBALS['bind_admin'] = true;
$GLOBALS['bind_profile'] = false;
$GLOBALS['bind_portable'] = true;
assert_bind( $c::observe_local_token_issued( $client, 7, $resource, array( 'mad4b:read' ) ),
    'Portable read-only sites auto-observe verified ChatGPT OAuth client without manual Site Profile.' );
$portable_status = $c::status();
assert_bind( $portable_status['client_observed'] &&
    $portable_status['site_identity_source'] === 'portable_readonly' &&
    $portable_status['provisional_readonly_binding'] &&
    !$c::current_bearer_binding_ready() && !$portable_status['write_grants_created'],
    'Portable connection is non-authorizing and cannot satisfy Site Profile Phase A.' );
$GLOBALS['bind_portable'] = false;
assert_bind( !$c::status()['client_observed'],
    'Disabling portable readiness invalidates its transient binding.' );
echo "PASS: ChatGPT OAuth automatic site-bound identity, portable read-only, stale/foreign rejection, no manual App ID or grants\n";
