<?php
// Inventory bounds must run before inspecting tools or resolving their bindings.
define( 'ABSPATH', __DIR__ . '/' );
class WP_Error { private $code; function __construct( $code, $message = '', $data = array() ) { $this->code = $code; } function get_error_code() { return $this->code; } }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function sanitize_key( $s ) { return strtolower( $s ); }
class FixtureAdapter { static $servers = array(); static function instance() { return new self(); } function get_servers() { return self::$servers; } }
class FixtureExposure {}
class_alias( 'FixtureAdapter', 'WP\\MCP\\Core\\McpAdapter' );
class_alias( 'FixtureExposure', 'WP\\MCP\\Abilities\\McpAbilityExposure' );
class FixtureServer {
 public $reads = 0; public $bindings = 0;
 function get_server_id() { return 'foreign'; }
 function get_tools() { $this->reads++; return array_fill( 0, 501, new stdClass() ); }
 function get_mcp_tool( $name ) { $this->bindings++; throw new RuntimeException( 'Unbounded binding lookup' ); }
}
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-mcp-peer-governance.php';
function check( $ok, $why ) { if ( ! $ok ) throw new RuntimeException( $why ); }
$server = new FixtureServer();
FixtureAdapter::$servers = array_fill( 0, 101, $server );
$status = MAD4B_SCP_MCP_Peer_Governance::status();
check( ! $status['inventory_ready'] && 'mcp_server_inventory_overflow' === $status['reason'] && 0 === $server->reads, 'Oversized server inventory inspected before bound' );
FixtureAdapter::$servers = array( $server );
$status = MAD4B_SCP_MCP_Peer_Governance::status();
check( ! $status['inventory_ready'] && 'mcp_tool_inventory_overflow' === $status['reason'] && 0 === $server->bindings, 'Oversized tool inventory traversed before bound' );
echo "MCP peer inventory bounds runtime: PASS\n";
