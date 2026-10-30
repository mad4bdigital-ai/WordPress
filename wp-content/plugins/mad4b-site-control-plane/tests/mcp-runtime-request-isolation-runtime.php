<?php
define( 'ABSPATH', __DIR__ . '/' );
define( 'MAD4B_SCP_VERSION', 'fixture' );
function add_filter( ...$args ) {}
class WP_Error { private $code; private $message; private $data; function __construct( $code = 'adapter_rejected', $message = 'fixture', $data = array() ) { $this->code = $code; $this->message = $message; $this->data = $data; } function get_error_code() { return $this->code; } function get_error_message() { return $this->message; } function get_error_data() { return $this->data; } }
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function sanitize_key( $v ) { return strtolower( $v ); }
function wp_json_encode( $v ) { return json_encode( $v ); }
function wp_has_ability( $name ) { return false; }
function wp_get_ability( $name ) { return new FixtureAbility(); }
class FixtureAbility { function get_input_schema() { return array( 'type' => 'object' ); } function get_output_schema() { return array( 'type' => 'object' ); } }
eval( 'namespace WP\\MCP\\Domain\\Tools; class RegisterAbilityAsMcpTool { public static $result = null; static function build( $a ) { return self::$result ?? new \\WP_Error(); } } class McpToolValidator { public static $result = true; static function validate_tool_dto( $dto ) { return self::$result; } }' );
class FixtureTool { function getName() { return 'mad4b-broken'; } function toArray() { return array( 'inputSchema' => array( 'type' => 'object', 'properties' => (object) array() ) ); } function get_adapter_meta() { return array( 'ability' => 'mad4b/broken' ); } }
class FixtureServer { function get_tools() { return array(); } function get_mcp_tool( $name ) { throw new RuntimeException( 'Failed tool was mounted' ); } }
class FixtureAdapter { public $reject = true; public $tools = null; public $error = null; function create_server( ...$args ) { $this->tools = $args[9]; return $this->reject ? ( $this->error ?: new WP_Error() ) : true; } function get_server( $id ) { return new FixtureServer(); } }
class MAD4B_SCP_Capability_Descriptor_Registry {
	const CONTRACT = 'mad4b.capability-descriptor.v2';
	const CONSUMER_BINDING_CONTRACT = 'mad4b.capability-descriptor-consumer-binding.v1';
	public static function binding( $name, $consumer ) {
		$name = (string) $name;
		return array(
			'contract' => self::CONSUMER_BINDING_CONTRACT,
			'consumer' => (string) $consumer,
			'descriptor_contract' => self::CONTRACT,
			'generation_contract' => 'mad4b.capability-generation-roots.v1',
			'ability_name' => $name,
			'input_schema_sha256' => hash( 'sha256', 'fixture-schema:' . $name ),
			'classification_sha256' => hash( 'sha256', 'fixture-classification:' . $name ),
			'execution_lane' => 'read',
			'descriptor_sha256' => hash( 'sha256', 'fixture-descriptor:' . $name ),
			'generation_roots' => array( 'contract_root' => str_repeat( 'a', 64 ), 'site_root' => str_repeat( 'b', 64 ) ),
			'authorizing' => false,
		);
	}
}
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-transport-context.php';
class MAD4B_SCP_Policy { public static $read = false; static function can_read() { return self::$read; } }
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-servers.php';

$mode = isset($argv[1]) ? (string) $argv[1] : 'cli';
if ( 'numeric' === $mode ) define( 'MAD4B_SCP_MCP_CLI_REQUEST', 1 );
define( 'WP_CLI', 'http' !== $mode );
function wp_unslash( $v ) { return $v; }
function wp_parse_url( $v, $piece = -1 ) { return parse_url( $v, $piece ); }
function rest_get_url_prefix() { return 'wp-json'; }
$target = new ReflectionMethod( 'MAD4B_SCP_Servers', 'current_request_server_id' );
$target->setAccessible( true );
$allow = new ReflectionMethod( 'MAD4B_SCP_Servers', 'should_materialize_server_tools' );
$allow->setAccessible( true );
function assertion( $ok, $msg ) { if ( ! $ok ) throw new RuntimeException( $msg ); }
if ( WP_CLI ) {
    unset( $_SERVER['REQUEST_URI'] );
    if ( 'numeric' === $mode ) {
        $numeric = $target->invoke(null);
        assertion( '' === $numeric, 'Truthy CLI marker must match canonical request scope' );
        assertion( $allow->invoke(null,'mad4b-chatgpt',$numeric), 'Numeric MCP marker must allow explicit catalog' );
    } else {
    putenv( 'MAD4B_SCP_MCP_CLI_REQUEST' );
    $generic = $target->invoke(null);
    assertion( '__mad4b_non_mcp_cli__' === $generic, 'Unscoped CLI must be route-only' );
    foreach ( MAD4B_SCP_Servers::expected_server_ids() as $server ) assertion( ! $allow->invoke( null, $server, $generic ), 'Generic CLI mounts server: '.$server );
    putenv( 'MAD4B_SCP_MCP_CLI_REQUEST=1' );
    $explicit = $target->invoke(null);
    assertion( '' === $explicit, 'Explicit MCP CLI opted into full materialization' );
    assertion( $allow->invoke( null, 'mad4b-chatgpt', $explicit ), 'Opt-in MCP CLI may materialize actual server' );
    putenv( 'MAD4B_SCP_MCP_CLI_REQUEST' );
    }
} else {
    $_SERVER['REQUEST_URI'] = '/wp-json/wp/v2/posts';
    $other = $target->invoke(null);
    assertion( '__mad4b_non_mcp_http__' === $other, 'Unrelated REST must not materialize MCP catalogs' );
    assertion( ! $allow->invoke(null,'mad4b-chatgpt',$other), 'Unrelated REST mounts ChatGPT' );
    $_SERVER['REQUEST_URI'] = '/wp-json/mcp/mad4b-chatgpt';
    $real = $target->invoke(null);
    assertion( 'mad4b-chatgpt' === $real, 'Real ChatGPT endpoint was not selected' );
    assertion( $allow->invoke(null,'mad4b-chatgpt',$real), 'Real MCP endpoint cannot mount target' );
    assertion( ! $allow->invoke(null,'mad4b-read',$real), 'Real MCP endpoint mounts unrelated read catalog' );
    $_SERVER['REQUEST_URI'] = '/index.php?rest_route=%2Fmcp%2Fmad4b-read';
    $query = $target->invoke(null);
    assertion( 'mad4b-read' === $query, 'Query-based REST MCP route not selected' );
}
echo "MCP_REQUEST_ISOLATION_".(WP_CLI?'CLI':'HTTP').": PASS\n";
