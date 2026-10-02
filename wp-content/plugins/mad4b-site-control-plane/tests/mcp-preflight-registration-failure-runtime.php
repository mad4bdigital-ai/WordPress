<?php
define( 'ABSPATH', __DIR__ . '/' );
define( 'MAD4B_SCP_VERSION', 'fixture' );
function add_filter( ...$args ) {}
class WP_Error { function get_error_code() { return 'adapter_rejected'; } function get_error_message() { return 'fixture'; } }
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function sanitize_key( $v ) { return strtolower( $v ); }
function wp_json_encode( $v ) { return json_encode( $v ); }
function wp_has_ability( $name ) { return false; }
function wp_get_ability( $name ) { return new FixtureAbility(); }
class FixtureAbility { function get_input_schema() { return array( 'type' => 'object' ); } function get_output_schema() { return array( 'type' => 'object' ); } }
eval( 'namespace WP\\MCP\\Domain\\Tools; class RegisterAbilityAsMcpTool { static function build( $a ) { return new \\WP_Error(); } } class McpToolValidator { static function validate_tool_dto( $dto ) { return true; } }' );
class FixtureTool { function getName() { return 'mad4b-broken'; } function toArray() { return array( 'inputSchema' => array( 'type' => 'object', 'properties' => (object) array() ) ); } function get_adapter_meta() { return array( 'ability' => 'mad4b/broken' ); } }
class FixtureServer { function get_tools() { return array( 'mad4b-broken' => new FixtureTool() ); } function get_mcp_tool( $name ) { return new FixtureTool(); } }
class FixtureAdapter { public $reject = true; function create_server( ...$args ) { return $this->reject ? new WP_Error() : true; } function get_server( $id ) { return new FixtureServer(); } }
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-servers.php';
function check( $ok, $why ) { if ( ! $ok ) throw new RuntimeException( $why ); }
$reflect = new ReflectionClass( MAD4B_SCP_Servers::class );
$servers = $reflect->newInstanceWithoutConstructor();
$create = $reflect->getMethod( 'create' ); $create->setAccessible( true );
$adapter = new FixtureAdapter();
$invoke = static function() use ( $create, $servers, $adapter ) { $create->invoke( $servers, $adapter, 'mad4b-chatgpt', 'fixture', 'fixture', array( 'mad4b/broken' ), null, null, null, null, true ); };
$invoke();
$row = MAD4B_SCP_Servers::registration_status()['mad4b-chatgpt'];
check( ! $row['registered'] && 'adapter_rejected' === $row['error'], 'adapter failure retained' );
check( 1 === $row['requested_tool_count'] && 'mad4b/broken' === $row['preflight']['failures'][0]['failing_ability'], 'earlier preflight survives adapter rejection' );
$adapter->reject = false; $invoke();
$row = MAD4B_SCP_Servers::registration_status()['mad4b-chatgpt'];
check( 'mcp_required_tool_preflight_failed' === $row['error'] && ! $row['catalog_evidence']['ready'], 'adapter success cannot overrule required preflight failure' );
check( array() === MAD4B_SCP_MCP_Catalog_Diagnostics::classification_snapshot(), 'failed preflight never freezes a ready classification' );
echo "MCP preflight registration failure runtime: PASS\n";
