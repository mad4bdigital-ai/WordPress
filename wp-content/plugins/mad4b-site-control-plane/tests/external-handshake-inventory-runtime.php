<?php
namespace WP\MCP\Domain\Utils {
	final class McpNameSanitizer {
		public static function sanitize_name( $name ) {
			$name = strtolower( trim( (string) $name ) );
			$name = str_replace( '/', '-', $name );
			$name = preg_replace( '/[^a-z0-9_.-]+/', '-', $name );
			return trim( (string) $name, '-' );
		}
	}
}
namespace {
if ( PHP_VERSION_ID < 70400 ) { fwrite( STDERR, "FAIL: PHP 7.4+ required\n" ); exit( 1 ); }
define( 'ABSPATH', __DIR__ . '/' );
define( 'REST_REQUEST', true );
$root = realpath( __DIR__ . '/../mad4b-site-control-plane' );
if ( false === $root ) $root = realpath( __DIR__ . '/..' );
if ( false === $root || ! is_file( $root . '/mad4b-site-control-plane.php' ) ) $root = realpath( dirname( __FILE__ ) . '/..' );
if ( false === $root || ! is_file( $root . '/mad4b-site-control-plane.php' ) ) { fwrite( STDERR, "FAIL: unable to resolve control-plane root\n" ); exit( 1 ); }
define( 'MAD4B_SCP_DIR', rtrim( $root, '/\\' ) . DIRECTORY_SEPARATOR );
define( 'MAD4B_SCP_FILE', MAD4B_SCP_DIR . 'mad4b-site-control-plane.php' );
$GLOBALS['mad4b_test_options'] = array(); $GLOBALS['mad4b_test_transients'] = array();
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
function set_transient( $name, $value, $ttl = 0 ) { $GLOBALS['mad4b_test_transients'][ $name ] = $value; return true; }
function get_transient( $name ) { return array_key_exists( $name, $GLOBALS['mad4b_test_transients'] ) ? $GLOBALS['mad4b_test_transients'][ $name ] : false; }
function delete_transient( $name ) { unset( $GLOBALS['mad4b_test_transients'][ $name ] ); return true; }
function rest_ensure_response( $value ) { return $value instanceof WP_REST_Response ? $value : new WP_REST_Response( $value ); }
class WP_REST_Response { private $data; private $status; private $headers; public function __construct( $data = null, $status = 200, $headers = array() ) { $this->data = $data; $this->status = $status; $this->headers = $headers; } public function get_data() { return $this->data; } public function get_status() { return $this->status; } public function get_headers() { return $this->headers; } }
final class MAD4B_Test_Request { private $method; private $headers; public function __construct( $method, array $headers ) { $this->method = $method; $this->headers = $headers; } public function get_route() { return '/mcp/mad4b-chatgpt'; } public function get_json_params() { return array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => $this->method ); } public function get_header( $name ) { $name = strtolower( (string) $name ); foreach ( $this->headers as $key => $value ) if ( strtolower( (string) $key ) === $name ) return $value; return ''; } }
final class MAD4B_SCP_OAuth_Resource_Bridge { public static function verified_bearer_active() { return true; } public static function resource_identifier() { return 'https://staging.egypttourgates.com/wp-json/mcp/mad4b-chatgpt'; } public static function status() { return array( 'issuer' => 'https://staging.egypttourgates.com/wp-json/mad4b-oauth', 'resource' => self::resource_identifier() ); } }
final class MAD4B_SCP_Identity_Context { public static function current() { return array( 'authenticated' => true, 'auth_method' => 'oauth2_bearer', 'subject_fingerprint' => str_repeat( 'a', 64 ), 'token_scopes' => array( 'mad4b:read' ), 'wp_user_id' => 7 ); } }
final class MAD4B_SCP_Site_Profile { public static function current_environment() { return 'staging'; } public static function origin_enrolled() { return true; } public static function oauth_enabled() { return true; } }
final class MAD4B_SCP_Servers {
	public static $chatgpt_tools = array(); public static $external_write_tools = array(); public static $write_tools = array(); public static $blocked_write_tools = array();
	public static function chatgpt_tools() { return self::$chatgpt_tools; }
	public static function external_write_tools() { return self::$external_write_tools; }
	public static function write_tools() { return self::$write_tools; }
	public static function blocked_write_tools() { return self::$blocked_write_tools; }
	public static function core_tools( $server_id ) { return 'mad4b-breakglass' === $server_id ? array( 'mad4b/database-raw-query' ) : array(); }
}
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-external-handshake-evidence.php';
function mad4b_fail( $message ) { fwrite( STDERR, 'FAIL: ' . $message . "\n" ); exit( 1 ); }
function mad4b_assert( $condition, $message ) { if ( ! $condition ) mad4b_fail( $message ); }
function mad4b_b64url( $value ) { return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' ); }
function mad4b_bearer() { $claims = array( 'iss' => 'https://staging.egypttourgates.com/wp-json/mad4b-oauth', 'resource' => MAD4B_SCP_OAuth_Resource_Bridge::resource_identifier(), 'client_id' => MAD4B_SCP_External_Handshake_Evidence::CHATGPT_CLIENT_ID ); return 'e30.' . mad4b_b64url( json_encode( $claims ) ) . '.c2ln'; }
function mad4b_tool_name( $ability ) { return \WP\MCP\Domain\Utils\McpNameSanitizer::sanitize_name( $ability ); }
function mad4b_external_names( array $abilities ) { return array_values( array_map( 'mad4b_tool_name', $abilities ) ); }
function mad4b_reset_state() { $GLOBALS['mad4b_test_options'] = array(); $GLOBALS['mad4b_test_transients'] = array(); }
function mad4b_initialize( $session_id ) { $request = new MAD4B_Test_Request( 'initialize', array( 'authorization' => 'Bearer ' . mad4b_bearer() ) ); $response = new WP_REST_Response( array( 'result' => array( 'capabilities' => array( 'tools' => array( 'listChanged' => false ) ), 'serverInfo' => array( 'name' => 'MAD4B ChatGPT MCP' ) ) ), 200, array( 'mcp-session-id' => $session_id ) ); MAD4B_SCP_External_Handshake_Evidence::observe_rest_response( $response, null, $request ); }
function mad4b_capture_tools( $session_id, array $names ) { $request = new MAD4B_Test_Request( 'tools/list', array( 'authorization' => 'Bearer ' . mad4b_bearer(), 'mcp-session-id' => $session_id ) ); $tools = array(); foreach ( $names as $name ) $tools[] = array( 'name' => $name ); MAD4B_SCP_External_Handshake_Evidence::observe_rest_response( new WP_REST_Response( array( 'result' => array( 'tools' => $tools ) ), 200 ), null, $request ); }
function mad4b_capture_scenario( $session_id, array $external_names ) { mad4b_reset_state(); mad4b_initialize( $session_id ); mad4b_capture_tools( $session_id, $external_names ); return get_option( MAD4B_SCP_External_Handshake_Evidence::OPTION, array() ); }

$core_writes = array( 'mad4b/approval-plan', 'mad4b/content-update-post', 'mad4b/database-update', 'mad4b/mutation-undo', 'mad4b/plugin-activate' );
$gated_provider = 'jetsmartfilters/update-filter-meta';
$stable_writes = array_merge( $core_writes, array( $gated_provider ) );
$reads = array( 'etg-dfsb/evidence-provider', 'etg-dfsb/evidence-query', 'mad4b/site-info' );
$write_transport = array( 'mad4b/write-discover', 'mad4b/write-info', 'mad4b/write-execute' );
MAD4B_SCP_Servers::$write_tools = $core_writes;
MAD4B_SCP_Servers::$external_write_tools = $stable_writes;
MAD4B_SCP_Servers::$chatgpt_tools = array_merge( $reads, $write_transport );
MAD4B_SCP_Servers::$blocked_write_tools = array( array( 'ability' => $gated_provider, 'provider' => 'jetsmartfilters' ) );
$exact_external = mad4b_external_names( MAD4B_SCP_Servers::$chatgpt_tools );

$evidence = mad4b_capture_scenario( 'session-exact', array_reverse( $exact_external ) );
mad4b_assert( ! empty( $evidence ), 'minimal governed transport inventory was not persisted' );
mad4b_assert( 'mad4b.external-handshake-evidence.v4' === $evidence['contract'], 'v4 evidence contract missing' );
mad4b_assert( count( $stable_writes ) === (int) $evidence['write_tool_count'], 'logical write count must use the full stable catalog' );
mad4b_assert( count( $core_writes ) === (int) $evidence['eligible_write_tool_count'], 'eligible logical write count must stay dynamic' );
mad4b_assert( 3 === (int) $evidence['write_transport_tool_count'], 'write transport trio must be captured' );
mad4b_assert( ! empty( $evidence['write_transport_ready'] ), 'write transport must be ready' );
mad4b_assert( empty( $evidence['direct_write_schema_leaks'] ), 'underlying write schemas must not be direct' );
mad4b_assert( 1 === (int) $evidence['provider_gated_write_tool_count'], 'gated provider write must be reported logically' );
mad4b_assert( in_array( mad4b_tool_name( $gated_provider ), $evidence['provider_gated_write_tools'], true ), 'gated provider write missing from evidence' );
$status = MAD4B_SCP_External_Handshake_Evidence::status();
mad4b_assert( ! empty( $status['verified'] ), 'minimal transport plus logical write catalog should verify' );
mad4b_assert( ! empty( $status['tool_inventory_match'] ), 'minimal transport fingerprint should match' );
mad4b_assert( ! empty( $status['write_inventory_fingerprint_match'] ), 'logical write catalog fingerprint should match' );

// Certification transition changes runtime eligibility only; it must not change
// either the external transport identity or the stable logical write catalog.
$before_transport_fp = $status['expected_tool_inventory_fingerprint'];
$before_write_fp = $status['write_catalog_fingerprint'];
MAD4B_SCP_Servers::$write_tools[] = $gated_provider;
MAD4B_SCP_Servers::$blocked_write_tools = array();
$status = MAD4B_SCP_External_Handshake_Evidence::status();
mad4b_assert( ! empty( $status['verified'] ), 'provider activation must not stale minimal external handshake' );
mad4b_assert( hash_equals( $before_transport_fp, $status['expected_tool_inventory_fingerprint'] ), 'provider activation must not change transport inventory fingerprint' );
mad4b_assert( hash_equals( $before_write_fp, $status['write_catalog_fingerprint'] ), 'provider activation must not change logical write catalog fingerprint' );
mad4b_assert( count( $stable_writes ) === (int) $status['expected_eligible_write_tool_count'], 'eligible write count should reflect newly active provider tool' );
mad4b_assert( 0 === (int) $status['provider_gated_write_tool_count'], 'gated count should clear after activation' );

$raw_sql = $exact_external; $raw_sql[] = mad4b_tool_name( 'mad4b/database-raw-query' );
mad4b_assert( empty( mad4b_capture_scenario( 'session-raw-sql', $raw_sql ) ), 'raw SQL/Breakglass tool must reject handshake evidence' );
$missing = array_values( array_diff( $exact_external, array( mad4b_tool_name( 'mad4b/write-info' ) ) ) );
mad4b_assert( empty( mad4b_capture_scenario( 'session-missing-transport', $missing ) ), 'missing write transport member must reject handshake evidence' );
$direct_write = $exact_external; $direct_write[] = mad4b_tool_name( 'mad4b/content-update-post' );
mad4b_assert( empty( mad4b_capture_scenario( 'session-direct-write-leak', $direct_write ) ), 'direct underlying write schema must reject handshake evidence' );
$unexpected = $exact_external; $unexpected[] = 'foreign-unexpected-tool';
mad4b_assert( empty( mad4b_capture_scenario( 'session-unexpected', $unexpected ) ), 'unexpected foreign tool must reject handshake evidence' );

echo "mad4b.external-handshake-inventory.v4: PASS\n";
}
