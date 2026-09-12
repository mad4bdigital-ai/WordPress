<?php
namespace WP\MCP\Domain\Utils {
    final class McpNameSanitizer {
        public static function sanitize_name( $name ) {
            $name = strtolower( trim( (string) $name ) );
            $name = str_replace( '/', '-', $name );
            $name = preg_replace( '/[^a-z0-9_.-]+/', '-', $name );
            $name = trim( (string) $name, '-' );
            return $name;
        }
    }
}

namespace {
if ( PHP_VERSION_ID < 70400 ) {
    fwrite( STDERR, "FAIL: PHP 7.4+ required\n" );
    exit( 1 );
}

define( 'ABSPATH', __DIR__ . '/' );
define( 'REST_REQUEST', true );
$root = realpath( __DIR__ . '/../mad4b-site-control-plane' );
if ( false === $root ) {
    $root = realpath( __DIR__ . '/..' );
}
if ( false === $root || ! is_file( $root . '/mad4b-site-control-plane.php' ) ) {
    // Normal repository location: tests/<this file>.
    $root = realpath( dirname( __FILE__ ) . '/..' );
}
if ( false === $root || ! is_file( $root . '/mad4b-site-control-plane.php' ) ) {
    fwrite( STDERR, "FAIL: unable to resolve control-plane root\n" );
    exit( 1 );
}
define( 'MAD4B_SCP_DIR', rtrim( $root, '/\\' ) . DIRECTORY_SEPARATOR );
define( 'MAD4B_SCP_FILE', MAD4B_SCP_DIR . 'mad4b-site-control-plane.php' );

$GLOBALS['mad4b_test_options'] = array();
$GLOBALS['mad4b_test_transients'] = array();

function add_filter() { return true; }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( (string) $value ); }
function esc_url_raw( $value ) { return (string) $value; }
function absint( $value ) { return abs( (int) $value ); }
function wp_get_environment_type() { return 'staging'; }
function wp_is_using_https() { return true; }
function wp_json_encode( $value ) { return json_encode( $value ); }
function is_wp_error( $value ) { return false; }
function untrailingslashit( $value ) { return rtrim( (string) $value, '/\\' ); }
function get_option( $name, $default = false ) { return array_key_exists( $name, $GLOBALS['mad4b_test_options'] ) ? $GLOBALS['mad4b_test_options'][ $name ] : $default; }
function update_option( $name, $value, $autoload = null ) { $GLOBALS['mad4b_test_options'][ $name ] = $value; return true; }
function delete_option( $name ) { unset( $GLOBALS['mad4b_test_options'][ $name ] ); return true; }
function set_transient( $name, $value, $ttl = 0 ) { $GLOBALS['mad4b_test_transients'][ $name ] = $value; return true; }
function get_transient( $name ) { return array_key_exists( $name, $GLOBALS['mad4b_test_transients'] ) ? $GLOBALS['mad4b_test_transients'][ $name ] : false; }
function delete_transient( $name ) { unset( $GLOBALS['mad4b_test_transients'][ $name ] ); return true; }
function rest_ensure_response( $value ) { return $value instanceof WP_REST_Response ? $value : new WP_REST_Response( $value ); }

class WP_REST_Response {
    private $data;
    private $status;
    private $headers;
    public function __construct( $data = null, $status = 200, $headers = array() ) {
        $this->data = $data;
        $this->status = $status;
        $this->headers = $headers;
    }
    public function get_data() { return $this->data; }
    public function get_status() { return $this->status; }
    public function get_headers() { return $this->headers; }
}

final class MAD4B_Test_Request {
    private $method;
    private $headers;
    public function __construct( $method, array $headers ) { $this->method = $method; $this->headers = $headers; }
    public function get_route() { return '/mcp/mad4b-chatgpt'; }
    public function get_json_params() { return array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => $this->method ); }
    public function get_header( $name ) {
        $name = strtolower( (string) $name );
        foreach ( $this->headers as $key => $value ) if ( strtolower( (string) $key ) === $name ) return $value;
        return '';
    }
}

final class MAD4B_SCP_OAuth_Resource_Bridge {
    public static function verified_bearer_active() { return true; }
    public static function resource_identifier() { return 'https://staging.egypttourgates.com/wp-json/mcp/mad4b-chatgpt'; }
    public static function status() {
        return array(
            'issuer' => 'https://staging.egypttourgates.com/wp-json/mad4b-oauth',
            'resource' => self::resource_identifier(),
        );
    }
}

final class MAD4B_SCP_Identity_Context {
    public static function current() {
        return array(
            'authenticated' => true,
            'auth_method' => 'oauth2_bearer',
            'subject_fingerprint' => str_repeat( 'a', 64 ),
            'token_scopes' => array( 'mad4b:read' ),
            'wp_user_id' => 7,
        );
    }
}

final class MAD4B_SCP_Servers {
    public static $chatgpt_tools = array();
    public static $write_tools = array();
    public static $blocked_write_tools = array();
    public static function chatgpt_tools() { return self::$chatgpt_tools; }
    public static function write_tools() { return self::$write_tools; }
    public static function blocked_write_tools() { return self::$blocked_write_tools; }
    public static function core_tools( $server_id ) { return 'mad4b-breakglass' === $server_id ? array( 'mad4b/database-raw-query' ) : array(); }
}

require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-external-handshake-evidence.php';

function mad4b_fail( $message ) { fwrite( STDERR, 'FAIL: ' . $message . "\n" ); exit( 1 ); }
function mad4b_assert( $condition, $message ) { if ( ! $condition ) mad4b_fail( $message ); }
function mad4b_b64url( $value ) { return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' ); }
function mad4b_bearer() {
    $claims = array(
        'iss' => 'https://staging.egypttourgates.com/wp-json/mad4b-oauth',
        'resource' => MAD4B_SCP_OAuth_Resource_Bridge::resource_identifier(),
        'client_id' => MAD4B_SCP_External_Handshake_Evidence::CHATGPT_CLIENT_ID,
    );
    return 'e30.' . mad4b_b64url( json_encode( $claims ) ) . '.c2ln';
}
function mad4b_tool_name( $ability ) { return \WP\MCP\Domain\Utils\McpNameSanitizer::sanitize_name( $ability ); }
function mad4b_external_names( array $abilities ) { return array_values( array_map( 'mad4b_tool_name', $abilities ) ); }
function mad4b_reset_state() { $GLOBALS['mad4b_test_options'] = array(); $GLOBALS['mad4b_test_transients'] = array(); }
function mad4b_initialize( $session_id ) {
    $request = new MAD4B_Test_Request( 'initialize', array( 'authorization' => 'Bearer ' . mad4b_bearer() ) );
    $response = new WP_REST_Response(
        array( 'result' => array( 'capabilities' => array( 'tools' => array( 'listChanged' => false ) ), 'serverInfo' => array( 'name' => 'MAD4B ChatGPT MCP' ) ) ),
        200,
        array( 'mcp-session-id' => $session_id )
    );
    MAD4B_SCP_External_Handshake_Evidence::observe_rest_response( $response, null, $request );
}
function mad4b_capture_tools( $session_id, array $names ) {
    $request = new MAD4B_Test_Request( 'tools/list', array( 'authorization' => 'Bearer ' . mad4b_bearer(), 'mcp-session-id' => $session_id ) );
    $tools = array();
    foreach ( $names as $name ) $tools[] = array( 'name' => $name );
    $response = new WP_REST_Response( array( 'result' => array( 'tools' => $tools ) ), 200 );
    MAD4B_SCP_External_Handshake_Evidence::observe_rest_response( $response, null, $request );
}
function mad4b_capture_scenario( $session_id, array $external_names ) {
    mad4b_reset_state();
    mad4b_initialize( $session_id );
    mad4b_capture_tools( $session_id, $external_names );
    return get_option( MAD4B_SCP_External_Handshake_Evidence::OPTION, array() );
}

$writes = array(
    'mad4b/approval-plan',
    'mad4b/content-update-post',
    'mad4b/database-update',
    'mad4b/filesystem-patch',
    'mad4b/filesystem-write',
    'mad4b/mutation-undo',
    'mad4b/plugin-activate',
    'mad4b/plugin-deactivate',
    'media/set-featured',
    'media/update-metadata',
);
$reads = array();
for ( $i = 1; $i <= 78; $i++ ) $reads[] = sprintf( 'read/tool-%03d', $i );
MAD4B_SCP_Servers::$write_tools = $writes;
MAD4B_SCP_Servers::$chatgpt_tools = array_merge( $reads, $writes );
MAD4B_SCP_Servers::$blocked_write_tools = array(
    array( 'ability' => 'elementor/update-widget-settings' ),
    array( 'ability' => 'jetengine/update-post-meta' ),
    array( 'ability' => 'seo/update-meta' ),
);
$exact_external = mad4b_external_names( MAD4B_SCP_Servers::$chatgpt_tools );
mad4b_assert( 88 === count( $exact_external ), 'fixture must represent the expected 88-tool external inventory' );

// 1. Fresh exact tools/list, including all 10 governed writes, must certify and persist.
$evidence = mad4b_capture_scenario( 'session-exact', array_reverse( $exact_external ) );
mad4b_assert( ! empty( $evidence ), 'exact governed inventory was not persisted' );
mad4b_assert( 'mad4b.external-handshake-evidence.v2' === $evidence['contract'], 'v2 evidence contract missing' );
mad4b_assert( 88 === (int) $evidence['tool_count'], 'exact external tool count must be 88' );
mad4b_assert( 10 === (int) $evidence['write_tool_count'], 'all 10 governed writes must be attested' );
mad4b_assert( ! empty( $evidence['tool_inventory_fingerprint'] ) && 64 === strlen( $evidence['tool_inventory_fingerprint'] ), 'inventory fingerprint missing' );
$status = MAD4B_SCP_External_Handshake_Evidence::status();
mad4b_assert( ! empty( $status['verified'] ), 'fresh exact inventory should verify' );
mad4b_assert( 'verified_external_chatgpt_session' === $status['status'], 'fresh exact inventory returned wrong status' );
mad4b_assert( ! empty( $status['tool_inventory_match'] ), 'fresh exact inventory must match current runtime' );
mad4b_assert( 88 === (int) $status['expected_tool_count'] && 10 === (int) $status['expected_write_tool_count'], 'expected counts mismatch' );
foreach ( array( 'mad4b/database-update', 'mad4b/content-update-post', 'mad4b/plugin-activate', 'mad4b/mutation-undo' ) as $allowed_write ) {
    mad4b_assert( in_array( mad4b_tool_name( $allowed_write ), $exact_external, true ), 'governed write absent from accepted fixture: ' . $allowed_write );
}

// 2. Raw SQL/Breakglass and provider-blocked write names must fail closed.
$raw_sql = $exact_external;
$raw_sql[] = mad4b_tool_name( 'mad4b/database-raw-query' );
mad4b_assert( empty( mad4b_capture_scenario( 'session-raw-sql', $raw_sql ) ), 'raw SQL/Breakglass tool must reject handshake evidence' );
$provider_blocked = $exact_external;
$provider_blocked[] = mad4b_tool_name( 'elementor/update-widget-settings' );
mad4b_assert( empty( mad4b_capture_scenario( 'session-provider-blocked', $provider_blocked ) ), 'provider-blocked tool must reject handshake evidence' );

// 3. Missing governed/runtime tool or unexpected foreign tool must reject.
$missing = $exact_external;
array_pop( $missing );
mad4b_assert( empty( mad4b_capture_scenario( 'session-missing', $missing ) ), 'missing expected tool must reject handshake evidence' );
$unexpected = $exact_external;
$unexpected[] = 'foreign-unexpected-tool';
mad4b_assert( empty( mad4b_capture_scenario( 'session-unexpected', $unexpected ) ), 'unexpected foreign tool must reject handshake evidence' );

// 4. A later runtime projection change invalidates previously good evidence.
$evidence = mad4b_capture_scenario( 'session-drift', $exact_external );
mad4b_assert( ! empty( $evidence ), 'drift fixture failed to establish fresh evidence first' );
MAD4B_SCP_Servers::$chatgpt_tools[] = 'read/tool-079';
$status = MAD4B_SCP_External_Handshake_Evidence::status();
mad4b_assert( empty( $status['verified'] ), 'inventory drift must invalidate prior evidence' );
mad4b_assert( 'stale_tool_inventory_evidence' === $status['status'], 'inventory drift must report stale_tool_inventory_evidence' );
mad4b_assert( empty( $status['tool_inventory_match'] ), 'drifted evidence must not report inventory match' );

$connection_source = file_get_contents( MAD4B_SCP_DIR . 'includes/class-mad4b-scp-connection-status.php' );
mad4b_assert( false !== strpos( $connection_source, "'stale_tool_inventory_evidence'" ), 'connection-status must recognize stale inventory evidence' );
mad4b_assert( false !== strpos( $connection_source, "'external_handshake_stale'" ), 'connection-status must map stale evidence to external_handshake_stale' );

echo "mad4b.external-handshake-inventory.v1: PASS\n";
}
