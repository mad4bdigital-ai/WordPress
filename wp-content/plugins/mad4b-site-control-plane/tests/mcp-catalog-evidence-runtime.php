<?php
define( 'ABSPATH', __DIR__ . '/' );
function add_filter( ...$args ) {}
function wp_json_encode( $data ) { return json_encode( $data ); }
final class MAD4B_SCP_OAuth_Resource_Bridge { public static $verified = true; public static function verified_bearer_active() { return self::$verified; } }
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-mcp-catalog-diagnostics.php';
function check( $ok, $message ) { if ( ! $ok ) throw new RuntimeException( $message ); }
class FixtureTool { public $name; public $ability; function __construct( $name, $ability ) { $this->name = $name; $this->ability = $ability; } function getName() { return $this->name; } function toArray() { return array( 'name' => $this->name, 'inputSchema' => array( 'type' => 'object', 'properties' => (object) array() ) ); } function get_adapter_meta() { return array( 'ability' => $this->ability ); } }
class FixtureServer { public $tools; function __construct( $tools ) { $this->tools = $tools; } function get_tools() { return $this->tools; } function get_mcp_tool( $name ) { return $this->tools[$name]; } }
class FixtureRequest { public $method = 'tools/list'; public $route = '/mcp/mad4b-chatgpt'; function get_route() { return $this->route; } function get_method() { return 'POST'; } function get_json_params() { return array( 'method' => $this->method, 'params' => array( 'secret' => 'DO_NOT_COPY' ) ); } function get_header( $key ) { return 'DO_NOT_COPY'; } }
class FixtureResponse { public $data; public $headers = array(); public $status = 200; function __construct( $data ) { $this->data = $data; } function get_data() { return $this->data; } function get_status() { return $this->status; } function header( $key, $value ) { $this->headers[$key] = $value; } }
$required_over_budget = array();
for ( $i = 0; $i < 37; $i++ ) $required_over_budget[] = 'mad4b/required-' . $i;
$budget = MAD4B_SCP_MCP_Catalog_Diagnostics::budget_projection( $required_over_budget, array() );
check( empty( $budget['ready'] ) && 'mcp_required_catalog_budget_exceeded' === $budget['blocker'], 'required transport budget overflow must fail closed' );

$required = array();
for ( $i = 0; $i < 34; $i++ ) $required[] = 'mad4b/required-' . $i;
$optional = array( 'mad4b/optional-critical', 'mad4b/optional-secondary', 'mad4b/optional-overflow' );
$budget = MAD4B_SCP_MCP_Catalog_Diagnostics::budget_projection( array_merge( $required, $optional ), $optional );
check( ! empty( $budget['ready'] ) && 36 === count( $budget['selected'] ), 'optional overflow must retain a bounded ready catalog' );
check( array( 'mad4b/optional-overflow' ) === $budget['excluded_optional'], 'optional overflow must degrade in deterministic priority order' );
check( in_array( 'mad4b/optional-critical', $budget['selected'], true ) && in_array( 'mad4b/optional-secondary', $budget['selected'], true ), 'higher-priority optional tools must survive budget degradation' );

$one = new FixtureTool( 'mad4b-site-info', 'mad4b/site-info' );
$server = new FixtureServer( array( $one->name => $one ) );
$expected = array( 'mad4b/site-info' );
$s = MAD4B_SCP_MCP_Catalog_Diagnostics::inspect( $server, $expected );
check( $s['ready'] && 1 === $s['tool_count'], 'actual DTO and internal ability binding agree' );
$s = MAD4B_SCP_MCP_Catalog_Diagnostics::inspect( $server, array( 'mad4b/site-info', 'mad4b/tool-discover' ) );
check( ! $s['ready'] && 1 === $s['tool_count'] && array( 'mad4b/tool-discover' ) === $s['missing_abilities'], 'requested count cannot impersonate actual count' );
$s = MAD4B_SCP_MCP_Catalog_Diagnostics::inspect( new FixtureServer( array() ), $expected );
check( 'mcp_catalog_empty' === $s['blocker'], 'registered empty server is not ready' );
$s = MAD4B_SCP_MCP_Catalog_Diagnostics::inspect( null, $expected );
check( ! $s['observed'], 'unmaterialized server stays observational' );
$two = new FixtureTool( 'mad4b-extra', 'mad4b/site-info' );
$s = MAD4B_SCP_MCP_Catalog_Diagnostics::inspect( new FixtureServer( array( $one->name => $one, $two->name => $two ) ), $expected );
check( 'mcp_catalog_duplicate_ability' === $s['blocker'], 'duplicate binding detected' );
$s = MAD4B_SCP_MCP_Catalog_Diagnostics::inspect( $server, array_fill( 0, 37, 'mad4b/site-info' ) );
check( 'mcp_catalog_budget_exceeded' === $s['blocker'], 'bounded inventory' );
$s = MAD4B_SCP_MCP_Catalog_Diagnostics::inspect( new FixtureServer( array( 'wrong-name' => $one ) ), $expected );
check( 'mcp_tool_identity_invalid' === $s['blocker'], 'registry key and DTO name must agree' );
$s = MAD4B_SCP_MCP_Catalog_Diagnostics::inspect( $server, array( 'mad4b/tool-discover' ) );
check( array( 'mad4b/site-info' ) === $s['unexpected_abilities'], 'unexpected ability detected' );
$request = new FixtureRequest();
$response = new FixtureResponse( array( 'jsonrpc' => '2.0', 'id' => 1, 'result' => array( 'tools' => array( array( 'name' => 'mad4b-site-info' ) ) ) ) );
$before = serialize( $response->data );
check( $response === MAD4B_SCP_MCP_Catalog_Diagnostics::observe_response( $response, null, $request ), 'response identity preserved' );
check( 'ready' === $response->headers['X-MAD4B-MCP-Outcome'] && $before === serialize( $response->data ), 'protocol payload remains unchanged' );
check( false === strpos( json_encode( $response->headers ), 'DO_NOT_COPY' ), 'headers do not leak request/session material' );
MAD4B_SCP_OAuth_Resource_Bridge::$verified = false;
$response = new FixtureResponse( array() );
MAD4B_SCP_MCP_Catalog_Diagnostics::observe_response( $response, null, $request );
check( empty( $response->headers ), 'unverified caller receives no diagnostics' );
MAD4B_SCP_OAuth_Resource_Bridge::$verified = true;
$request->route = '/mcp/mad4b-write';
MAD4B_SCP_MCP_Catalog_Diagnostics::observe_response( $response, null, $request );
check( empty( $response->headers ), 'other authority planes excluded' );
$request->route = '/mcp/mad4b-chatgpt'; $request->method = 'tools/call';
MAD4B_SCP_MCP_Catalog_Diagnostics::observe_response( $response, null, $request );
check( empty( $response->headers ), 'mutation calls excluded' );
$request->method = 'initialize';
$response = new FixtureResponse( array( 'error' => array( 'code' => -32603, 'message' => 'DO_NOT_COPY' ) ) );
$log = tempnam( sys_get_temp_dir(), 'mad4b-catalog-test-' ); ini_set( 'error_log', $log );
MAD4B_SCP_MCP_Catalog_Diagnostics::observe_response( $response, null, $request );
$logged = file_get_contents( $log );
check( 'rpc_error' === $response->headers['X-MAD4B-MCP-Outcome'], 'RPC failure identified separately' );
check( false === strpos( $logged, 'DO_NOT_COPY' ) && false !== strpos( $logged, '-32603' ), 'failure log includes numeric code without sensitive payload' );
MAD4B_SCP_MCP_Catalog_Diagnostics::observe_response( $response, null, $request );
check( $logged === file_get_contents( $log ), 'bounded one failure log per request' ); unlink( $log );
$bad = new FixtureResponse( array( 'result' => (object) array( 'tools' => array() ) ) );
MAD4B_SCP_MCP_Catalog_Diagnostics::observe_response( $bad, null, $request );
check( 'rpc_error' === $bad->headers['X-MAD4B-MCP-Outcome'], 'malformed result cannot crash discovery diagnostics' );
echo "MCP catalog evidence runtime: PASS\n";
