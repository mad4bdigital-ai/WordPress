<?php
define( 'ABSPATH', __DIR__ . '/' );

class WP_Error {
	private $code;
	public function __construct( $code, $message = '', $data = null ) { $this->code = (string) $code; }
	public function get_error_code() { return $this->code; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( (string) $value ); }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function is_multisite() { return false; }
function get_option( $key, $default = null ) { return 'active_plugins' === $key ? array() : $default; }
function get_site_option( $key, $default = null ) { return $default; }
function wp_get_abilities() { return array(); }
function wp_has_ability( $name ) { return false; }
function wp_get_ability( $name ) { return null; }

$GLOBALS['fixture_actions'] = array( 'wp_loaded' => 0 );
$GLOBALS['wp_rest_server'] = null;
$GLOBALS['fixture_rest_bootstrap_calls'] = 0;

function did_action( $name ) { return isset( $GLOBALS['fixture_actions'][ $name ] ) ? (int) $GLOBALS['fixture_actions'][ $name ] : 0; }
function doing_action( $name ) { return false; }

final class FixtureTool {
	public function get_adapter_meta() { return array( 'ability' => 'mad4b/site-info' ); }
}

final class FixtureServer {
	public function get_server_id() { return 'mad4b-chatgpt'; }
	public function get_tools() { return array( 'mad4b-site-info' => new stdClass() ); }
	public function get_mcp_tool( $name ) { return 'mad4b-site-info' === $name ? new FixtureTool() : null; }
	public function get_server_route_namespace() { return 'mcp'; }
	public function get_server_route() { return '/mad4b-chatgpt'; }
}

final class FixtureRestServer {
	public function get_routes() {
		return array(
			'/mcp/mad4b-chatgpt' => array(),
		);
	}
}

final class FixtureAdapter {
	const VERSION = '0.7.0';
	public static $servers = array();
	public static function instance() { return new self(); }
	public function get_servers() { return self::$servers; }
}

final class FixtureExposure {
	public static function is_public( $ability ) { return false; }
}

class_alias( 'FixtureAdapter', 'WP\\MCP\\Core\\McpAdapter' );
class_alias( 'FixtureExposure', 'WP\\MCP\\Abilities\\McpAbilityExposure' );

final class MAD4B_SCP_Servers {
	public static function expected_server_ids() { return array( 'mad4b-chatgpt' ); }
}

function rest_get_server() {
	$GLOBALS['fixture_rest_bootstrap_calls']++;
	if ( ! is_object( $GLOBALS['wp_rest_server'] ) ) {
		// This mirrors the important lifecycle effect of canonical lazy REST
		// initialization: MCP Adapter/MAD4B server registration completes before
		// the peer inventory is captured.
		FixtureAdapter::$servers = array( new FixtureServer() );
		$GLOBALS['wp_rest_server'] = new FixtureRestServer();
	}
	return $GLOBALS['wp_rest_server'];
}

require dirname( __DIR__ ) . '/includes/class-mad4b-scp-mcp-peer-governance.php';

function check( $condition, $message ) {
	if ( ! $condition ) throw new RuntimeException( $message );
}

// Before wp_loaded the peer inventory must fail closed and must not start REST.
$blocked = MAD4B_SCP_MCP_Peer_Governance::status();
check( empty( $blocked['inventory_ready'] ), 'Pre-wp_loaded peer inventory unexpectedly became ready.' );
check( isset( $blocked['reason'] ) && 'rest_bootstrap_incomplete' === $blocked['reason'], 'Pre-wp_loaded refusal lost exact reason.' );
check( 0 === $GLOBALS['fixture_rest_bootstrap_calls'], 'Peer inventory started REST before wp_loaded.' );

// On the wp-admin/admin-post lifecycle, canonical lazy REST initialization must
// happen before Adapter server capture. The route created by that lifecycle is
// therefore known MAD4B transport, not a false foreign MCP route.
$GLOBALS['fixture_actions']['wp_loaded'] = 1;
$ready = MAD4B_SCP_MCP_Peer_Governance::status();
check( ! empty( $ready['inventory_ready'] ), 'Post-wp_loaded peer inventory did not become ready.' );
check( 1 === $GLOBALS['fixture_rest_bootstrap_calls'], 'Canonical REST lifecycle was not initialized exactly once.' );
check( 1 === (int) $ready['server_count'], 'Adapter server inventory was captured before canonical REST initialization.' );
check( empty( $ready['foreign_mcp_detected'] ), 'MAD4B self-route was falsely classified as foreign MCP transport.' );
check( empty( $ready['foreign_transport_unreviewed'] ), 'MAD4B self-route became an unreviewed transport blocker.' );
check( empty( $ready['write_side_channel_detected'] ), 'MAD4B self-route became a write side-channel blocker.' );
check( isset( $ready['inventory_lifecycle_state'] ) && 'canonical_rest_initialized' === $ready['inventory_lifecycle_state'], 'Peer inventory did not report canonical REST synchronization.' );

$foreign = isset( $ready['foreign_transport_inventory'] ) ? $ready['foreign_transport_inventory'] : array();
check( isset( $foreign['foreign_route_count'] ) && 0 === (int) $foreign['foreign_route_count'], 'Known MAD4B route leaked into foreign route inventory.' );

// A second read reuses the existing REST server and remains mutation-free.
$again = MAD4B_SCP_MCP_Peer_Governance::status();
check( ! empty( $again['inventory_ready'] ), 'Repeated peer inventory lost readiness.' );
check( 1 === $GLOBALS['fixture_rest_bootstrap_calls'], 'Repeated peer inventory reinitialized REST.' );
check( isset( $again['inventory_lifecycle_state'] ) && 'already_initialized' === $again['inventory_lifecycle_state'], 'Repeated inventory did not reuse initialized REST.' );
check( ! empty( $again['inventory_lifecycle_read_only'] ), 'Lifecycle synchronization was not marked read-only.' );
check( empty( $again['inventory_lifecycle_mutation_performed'] ), 'Lifecycle synchronization reported persistent mutation.' );

echo "MCP peer admin REST lifecycle runtime: PASS\n";
