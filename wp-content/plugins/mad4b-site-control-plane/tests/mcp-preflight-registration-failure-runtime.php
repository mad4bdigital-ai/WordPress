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
class MAD4B_SCP_Transport_Context { public static $result = true; public static $calls = 0; static function bind( $server, $request ) { self::$calls++; return self::$result; } }
class MAD4B_SCP_Policy { public static $read = false; static function can_read() { return self::$read; } }
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
check( array() === $adapter->tools, 'invalid required tools never enter Adapter rebuild' );
$adapter->error = new WP_Error(
	'server_creation_failed',
	'Failed to create server "mad4b-chatgpt": Too few arguments to function WP\\MCP\\Core\\McpComponentRegistry::__construct(), 3 passed in /PRIVATE/PATH and exactly 4 expected'
);
$invoke();
$row = MAD4B_SCP_Servers::registration_status()['mad4b-chatgpt'];
check( 'server_creation_failed' === $row['error'], 'server construction error code was not retained' );
check( 'server_construction' === $row['registration_failure']['stage'], 'server construction failure stage was not bounded' );
check( 'runtime_constructor_contract_mismatch' === $row['registration_failure']['reason'], 'constructor ABI mismatch was not classified' );
check( 1 === preg_match( '/^[a-f0-9]{64}$/D', $row['registration_failure']['fingerprint'] ), 'registration failure fingerprint missing' );
check( false === strpos( json_encode( $row ), '/PRIVATE/PATH' ), 'raw server construction detail leaked into registration status' );

$adapter->error = null;
$adapter->reject = false; $invoke();
$row = MAD4B_SCP_Servers::registration_status()['mad4b-chatgpt'];
check( 'mcp_required_tool_preflight_failed' === $row['error'] && ! $row['catalog_evidence']['ready'], 'adapter success cannot overrule required preflight failure' );
check( array() === MAD4B_SCP_MCP_Catalog_Diagnostics::classification_snapshot(), 'failed preflight never freezes a ready classification' );
check( array() === $adapter->tools && 0 === $row['catalog_evidence']['tool_count'], 'failed preflight creates only a route, preserving failure evidence' );
foreach ( array( true, array(), array( 'tool' => 'invalid' ), array( 'tool' => new stdClass() ) ) as $malformed ) {
	\WP\MCP\Domain\Tools\RegisterAbilityAsMcpTool::$result = $malformed;
	$result = MAD4B_SCP_MCP_Catalog_Diagnostics::preflight( array( 'mad4b/broken' ), array() );
	check( ! $result['ready'] && 'mad4b_mcp_legacy_builder_contract_invalid' === $result['failures'][0]['error_code'] && 'official_wire_build' === $result['failures'][0]['stage'], 'malformed builder contract lost precise compatibility evidence' );
}
$dto = new class {
	public $calls = 0;
	function getName() { return 'mad4b-broken'; }
	function toArray() { if ( ++$this->calls > 1 ) throw new RuntimeException( 'SECOND_SERIALIZATION_PRIVATE' ); return array( 'name' => 'mad4b-broken', 'inputSchema' => array( 'type' => 'object' ) ); }
};
\WP\MCP\Domain\Tools\RegisterAbilityAsMcpTool::$result = array( 'tool' => $dto, 'adapter_meta' => array( 'ability' => 'mad4b/broken' ) );
\WP\MCP\Domain\Tools\McpToolValidator::$result = new WP_Error( 'dto_validator_rejected', 'Tool validation failed: Tool inputSchema properties must be an object/array' );
$result = MAD4B_SCP_MCP_Catalog_Diagnostics::preflight( array( 'mad4b/broken' ), array() );
check( 'dto_validator_rejected' === $result['failures'][0]['error_code'] && 'official_schema_validation' === $result['failures'][0]['stage'] && 1 === $dto->calls, 'earlier validator failure was overwritten by repeated serialization' );
check( 'input_schema_properties_not_object' === $result['failures'][0]['validator_reason'], 'bounded validator reason did not preserve the useful schema classification' );
check( false === strpos( json_encode( $result ), 'Tool validation failed:' ), 'raw validator message leaked into bounded preflight evidence' );
// Reuse the deliberate one-shot DTO for a separate validator case without
// turning the second assertion into a serialization-exception test.
$dto->calls = 0;
\WP\MCP\Domain\Tools\McpToolValidator::$result = new WP_Error( 'dto_validator_rejected', 'PRIVATE VALIDATOR DETAIL 123' );
$fallback = MAD4B_SCP_MCP_Catalog_Diagnostics::preflight( array( 'mad4b/broken' ), array() );
check( 'validator_rejected' === $fallback['failures'][0]['validator_reason'], 'unknown validator text must collapse to a bounded generic reason' );
check( false === strpos( json_encode( $fallback ), 'PRIVATE VALIDATOR DETAIL 123' ), 'unknown validator text escaped diagnostics' );

// Structural classification must not depend on the active WordPress locale or
// the translated upstream error text.
$locale_dto = new class {
	function getName() { return 'mad4b-broken'; }
	function toArray() { return array( 'name' => 'mad4b-broken', 'inputSchema' => array( 'type' => 'object', 'properties' => 'not-an-object' ) ); }
};
\WP\MCP\Domain\Tools\RegisterAbilityAsMcpTool::$result = array( 'tool' => $locale_dto, 'adapter_meta' => array( 'ability' => 'mad4b/broken' ) );
\WP\MCP\Domain\Tools\McpToolValidator::$result = new WP_Error( 'mcp_tool_validation_failed', 'رسالة تحقق مترجمة لا تحتوي المصطلحات الإنجليزية' );
$localized = MAD4B_SCP_MCP_Catalog_Diagnostics::preflight( array( 'mad4b/broken' ), array() );
check( 'input_schema_properties_not_object' === $localized['failures'][0]['validator_reason'], 'validator reason must derive from DTO structure, not translated text' );
check( false === strpos( json_encode( $localized ), 'رسالة تحقق مترجمة' ), 'translated validator text escaped diagnostics' );

$denied = MAD4B_SCP_Servers::can_read_transport();
check( is_wp_error( $denied ) && 'mad4b_transport_read_permission_denied' === $denied->get_error_code() && 403 === $denied->get_error_data()['status'], 'read capability denial is classified without granting access' );
MAD4B_SCP_Policy::$read = true;
check( true === MAD4B_SCP_Servers::can_read_transport(), 'authorized read transport remains allowed' );
$not_ready = MAD4B_SCP_Servers::can_chatgpt_transport();
check( is_wp_error( $not_ready ) && 'mad4b_mcp_catalog_not_ready' === $not_ready->get_error_code() && 503 === $not_ready->get_error_data()['status'], 'route-only ChatGPT transport denies catalog discovery' );
$bound_error = new WP_Error( 'exact_binding_denied' ); MAD4B_SCP_Transport_Context::$result = $bound_error;
check( $bound_error === MAD4B_SCP_Servers::can_read_transport(), 'binding denial remains authoritative' );
MAD4B_SCP_Transport_Context::$result = true; MAD4B_SCP_Policy::$read = $bound_error;
check( $bound_error === MAD4B_SCP_Servers::can_read_transport(), 'policy WP_Error is preserved verbatim' );
echo "MCP preflight registration failure runtime: PASS\n";
