<?php
// Offline registration only: real bundled Adapter/DTOs and production schemas.
// No Ability execution, authentication, site options or HTTP requests.
define( 'ABSPATH', __DIR__ . '/' );
define( 'MAD4B_SCP_VERSION', 'offline-fixture' );
function add_action( ...$args ) {}
function add_filter( ...$args ) {}
function has_filter( ...$args ) { return false; }
function do_action( ...$args ) {}
function __( $value, $domain = '' ) { return $value; }
function esc_html( $value ) { return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' ); }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function apply_filters( $hook, $value, ...$args ) { return isset( $GLOBALS['filters'][$hook] ) ? $GLOBALS['filters'][$hook]( $value, ...$args ) : $value; }
function wp_has_ability( $name ) { return isset( $GLOBALS['abilities'][$name] ); }
function wp_get_ability( $name ) { return $GLOBALS['abilities'][$name] ?? null; }
function wp_register_ability( $name, $args ) { $GLOBALS['abilities'][$name] = new WP_Ability( $name, $args ); }
class WP_Error {
	private $code; private $message; private $data;
	function __construct( $code, $message = '', $data = null ) { $this->code = $code; $this->message = $message; $this->data = $data; }
	function get_error_code() { return $this->code; }
	function get_error_message() { return $this->message; }
	function get_error_data() { return $this->data; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
class WP_Ability {
	private $name; private $args;
	function __construct( $name, $args ) { $this->name = $name; $this->args = $args; }
	function get_name() { return $this->name; }
	function get_label() { return $this->args['label'] ?? ''; }
	function get_description() { return $this->args['description'] ?? ''; }
	function get_meta() { return $this->args['meta'] ?? array(); }
	function get_input_schema() { return $this->args['input_schema'] ?? null; }
	function get_output_schema() { return $this->args['output_schema'] ?? null; }
	function execute() { throw new RuntimeException( 'Offline schema test executed an Ability' ); }
}
function check( $ok, $message ) { if ( ! $ok ) throw new RuntimeException( $message ); }
$adapter = isset( $argv[1] ) ? rtrim( $argv[1], '/' ) : '';
check( is_file( $adapter . '/vendor/autoload.php' ), 'Pass the materialized bundled mcp-adapter directory' );
require $adapter . '/vendor/autoload.php';
$include = dirname( __DIR__ ) . '/includes/';
$registrations = array(
	'identifiers' => null,
	'abilities' => null,
	'site-profile' => array( 'MAD4B_SCP_Site_Profile', 'register_ability' ),
	'read-consistency' => array( 'MAD4B_SCP_Read_Consistency', 'register_session_safe_report' ),
	'chatgpt-tool-projection' => array( 'MAD4B_SCP_ChatGPT_Tool_Projection', 'register_abilities' ),
	'plugin-package' => array( 'MAD4B_SCP_Plugin_Package', 'register_abilities' ),
	'remote-plugin-update' => array( 'MAD4B_SCP_Remote_Plugin_Update', 'register_abilities' ),
	'self-update' => array( 'MAD4B_SCP_Self_Update', 'register_abilities' ),
	'remote-operation-parity' => array( 'MAD4B_SCP_Remote_Operation_Parity', 'register_abilities' ),
	'provider-closure-matrix' => array( 'MAD4B_SCP_Provider_Closure_Matrix', 'register_ability' ),
	'staging-write-candidate-binding' => array( 'MAD4B_SCP_Staging_Write_Candidate_Binding', 'register_audit_ability' ),
	'staging-write-grant-reconciliation-plan' => array( 'MAD4B_SCP_Staging_Write_Grant_Reconciliation_Plan', 'register_ability' ),
	'staging-write-grant-reconciliation' => null,
	'full-staging-authority' => array( 'MAD4B_SCP_Full_Staging_Authority', 'register_abilities' ),
	'governed-runtime-gates' => array( 'MAD4B_SCP_Governed_Runtime_Gates', 'register_abilities' ),
);
foreach ( $registrations as $file => $callback ) {
	require $include . 'class-mad4b-scp-' . $file . '.php';
	if ( $callback ) call_user_func( $callback );
}
( new MAD4B_SCP_Abilities() )->register_abilities();
require $include . 'class-mad4b-scp-servers.php';
$required = array_merge( MAD4B_SCP_Servers::chatgpt_direct_read_transport_tools(), MAD4B_SCP_Servers::chatgpt_dispatch_transport_tools() );
foreach ( $required as $name ) check( wp_has_ability( $name ), 'Required production registration missing: ' . $name );
$preflight = MAD4B_SCP_MCP_Catalog_Diagnostics::preflight( $required, array() );
check( $preflight['ready'] && ! $preflight['failures'] && count( $required ) === count( $preflight['tools'] ), 'Production required DTO preflight failed: ' . json_encode( $preflight ) );
printf( "PASS bundled Adapter: %d production required schemas, %d wire bytes\n", count( $required ), $preflight['serialized_tool_bytes'] );
$GLOBALS['filters']['mcp_adapter_validation_enabled'] = static function() { return true; };
$deep_preflight = MAD4B_SCP_MCP_Catalog_Diagnostics::preflight( $required, array() );
check( $deep_preflight['ready'] && ! $deep_preflight['failures'], 'Production schemas failed optional official deep validation' );
unset( $GLOBALS['filters']['mcp_adapter_validation_enabled'] );

// Parameter-less DTOs must pass the official builder and wire validation.
foreach ( array( null, array(), array( 'type' => 'object' ), array( 'type' => 'object', 'properties' => array() ) ) as $input ) {
	wp_register_ability( 'fixture/no-input', array( 'input_schema' => $input ) );
	$result = MAD4B_SCP_MCP_Catalog_Diagnostics::preflight( array( 'fixture/no-input' ), array() );
	check( $result['ready'], 'Parameter-less schema failed official DTO conversion' );
}
// Faults that can explain the aggregate blocker, without inventing live state.
$result = MAD4B_SCP_MCP_Catalog_Diagnostics::preflight( array( 'fixture/missing' ), array() );
check( ! $result['ready'] && 'ability_missing' === $result['failures'][0]['error_code'] && 'ability_lookup' === $result['failures'][0]['stage'], 'Missing Ability lost its diagnostic stage' );
$result = MAD4B_SCP_MCP_Catalog_Diagnostics::preflight( array( $required[0], 'fixture/missing' ), array( 'fixture/missing' ) );
check( $result['ready'] && $result['degraded'], 'Missing optional Ability blocked required discovery' );
$GLOBALS['abilities']['fixture/schema-exception'] = new class( 'fixture/schema-exception', array() ) extends WP_Ability {
	function get_input_schema() { throw new RuntimeException( 'PRIVATE_SCHEMA_DETAILS' ); }
};
$result = MAD4B_SCP_MCP_Catalog_Diagnostics::preflight( array( 'fixture/schema-exception' ), array() );
check( ! $result['ready'] && 'source_schema_read' === $result['failures'][0]['stage'] && false === strpos( json_encode( $result ), 'PRIVATE_SCHEMA_DETAILS' ), 'Source exception stage lost or private details exposed' );
wp_register_ability( 'fixture/invalid-utf8', array( 'description' => "\xB1\x31" ) );
$result = MAD4B_SCP_MCP_Catalog_Diagnostics::preflight( array( 'fixture/invalid-utf8' ), array() );
check( ! $result['ready'] && 'dto_serialization' === $result['failures'][0]['stage'], 'Invalid UTF-8 was admitted to wire catalog' );
$GLOBALS['filters']['mcp_adapter_tool_name'] = static function() { return 'invalid tool name'; };
$result = MAD4B_SCP_MCP_Catalog_Diagnostics::preflight( array( $required[0] ), array() );
check( ! $result['ready'] && 'official_dto_build' === $result['failures'][0]['stage'] && 'mcp_tool_name_filter_invalid' === $result['failures'][0]['error_code'], 'Invalid third-party name filter lost precise builder evidence' );
$GLOBALS['filters']['mcp_adapter_tool_name'] = static function() { return 'colliding-name'; };
$result = MAD4B_SCP_MCP_Catalog_Diagnostics::preflight( array_slice( $required, 0, 2 ), array( $required[1] ) );
check( ! $result['ready'] && 'identity' === $result['failures'][0]['stage'], 'Optional tool identity collision must fail closed' );
unset( $GLOBALS['filters']['mcp_adapter_tool_name'] );
wp_register_ability( 'fixture/large', array( 'description' => str_repeat( 'x', 100000 ) ) );
$result = MAD4B_SCP_MCP_Catalog_Diagnostics::preflight( array( $required[0], 'fixture/large' ), array( 'fixture/large' ) );
check( $result['ready'] && $result['degraded'] && array( $required[0] ) === $result['tools'] && 'mcp_optional_catalog_size_excluded' === $result['failures'][0]['error_code'], 'Large optional DTO displaced required tools' );
$result = MAD4B_SCP_MCP_Catalog_Diagnostics::preflight( array( 'fixture/large' ), array() );
check( ! $result['ready'] && 'mcp_required_catalog_size_exceeded' === $result['failures'][0]['error_code'], 'Large required DTO exceeded wire budget' );
echo "PASS real DTO faults: empty input, invalid name filter, identity collision and size budgets\n";
