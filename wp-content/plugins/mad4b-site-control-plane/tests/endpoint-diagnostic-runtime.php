<?php
// Offline job security and real single-catalog registration; no live WordPress or HTTP.
$adapter_dir = $argv[1] ?? '';
$case = $argv[2] ?? '';
if ( '' === $case ) {
	foreach ( array( 'capability', 'nonce', 'array_input', 'server', 'build', 'method', 'foreign_adapter', 'early_rest', 'success', 'evidence' ) as $mode ) {
		// Inherit the runner's module configuration: PHP 7.4 loads JSON as an extension.
		$command = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' ' . escapeshellarg( $adapter_dir ) . ' ' . escapeshellarg( $mode );
		passthru( $command, $code );
		if ( $code ) exit( $code );
	}
	exit;
}
define( 'ABSPATH', __DIR__ . '/' );
define( 'MAD4B_SCP_DIR', dirname( __DIR__ ) . '/' );
define( 'MAD4B_SCP_VERSION', 'fixture' );
$fixture = sys_get_temp_dir() . '/mad4b-endpoint-' . uniqid();
mkdir( $fixture . '/plugins/foreign', 0700, true );
symlink( realpath( $adapter_dir ), $fixture . '/plugins/mcp-adapter' );
define( 'WP_PLUGIN_DIR', $fixture . '/plugins' );
register_shutdown_function( static function() use ( $fixture ) { unlink( $fixture . '/plugins/mcp-adapter' ); unlink( $fixture . '/plugins/foreign/callback.php' ); rmdir( $fixture . '/plugins/foreign' ); rmdir( $fixture . '/plugins' ); rmdir( $fixture ); } );
file_put_contents( $fixture . '/plugins/foreign/callback.php', '<?php function endpoint_foreign_registrar() { throw new RuntimeException("unrelated provider registrar ran"); }' );
require $fixture . '/plugins/foreign/callback.php';
class WP_Error { private $code; private $data; function __construct( $code, $message = '', $data = array() ) { $this->code = $code; $this->data = $data; } function get_error_code() { return $this->code; } function get_error_data() { return $this->data; } }
class WP_Hook { public $callbacks = array(); }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function hook_key( $cb ) { return is_array( $cb ) ? ( is_object( $cb[0] ) ? spl_object_hash( $cb[0] ) : $cb[0] ) . ':' . $cb[1] : ( is_object( $cb ) ? spl_object_hash( $cb ) : $cb ); }
function add_action( $name, $cb, $priority = 10, $args = 1 ) { if ( ! isset( $GLOBALS['wp_filter'][$name] ) ) $GLOBALS['wp_filter'][$name] = new WP_Hook(); $GLOBALS['wp_filter'][$name]->callbacks[$priority][hook_key( $cb )] = array( 'function' => $cb ); }
function has_action( $name, $cb ) { foreach ( $GLOBALS['wp_filter'][$name]->callbacks ?? array() as $p => $rows ) if ( isset( $rows[hook_key( $cb )] ) ) return $p; return false; }
function remove_action( $name, $cb, $priority = 10 ) { $found = isset( $GLOBALS['wp_filter'][$name]->callbacks[$priority][hook_key( $cb )] ); unset( $GLOBALS['wp_filter'][$name]->callbacks[$priority][hook_key( $cb )] ); return $found; }
function add_filter( ...$args ) {}
function remove_filter( ...$args ) { return false; }
function apply_filters( $name, $value, ...$args ) { return 'mcp_adapter_create_default_server' === $name ? false : $value; }
function do_action( $name, ...$args ) { $GLOBALS['did'][$name] = 1 + ( $GLOBALS['did'][$name] ?? 0 ); $priorities = array_keys( $GLOBALS['wp_filter'][$name]->callbacks ?? array() ); sort( $priorities ); foreach ( $priorities as $p ) foreach ( $GLOBALS['wp_filter'][$name]->callbacks[$p] ?? array() as $row ) call_user_func_array( $row['function'], $args ); }
function did_action( $name ) { return $GLOBALS['did'][$name] ?? 0; }
function is_admin() { return true; }
function wp_doing_ajax() { return true; }
function current_user_can( $cap ) { return 'capability' !== $GLOBALS['case']; }
function wp_verify_nonce( $nonce, $action ) { return 'valid' === $nonce && 'mad4b_connection_deep_endpoints' === $action ? 1 : false; }
function wp_unslash( $value ) { return $value; }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $value ) ); }
function sanitize_text_field( $value ) { return strip_tags( $value ); }
function wp_normalize_path( $value ) { return str_replace( '\\', '/', $value ); }
function trailingslashit( $value ) { return rtrim( $value, '/' ) . '/'; }
function wp_parse_url( $value, $component = -1 ) { return parse_url( $value, $component ); }
function rest_url( $path ) { return 'https://fixture.test/wp-json/' . $path; }
function esc_url_raw( $value ) { return $value; }
function get_option( $name, $default = false ) { return $default; }
function register_initial_settings() { throw new RuntimeException( 'Core settings registrar ran' ); }
function create_initial_rest_routes() { throw new RuntimeException( 'Core routes registrar ran' ); }
function endpoint_preserved_registrar() { $GLOBALS['preserved']++; }
class MAD4B_SCP_Site_Profile { static function origin_enrolled() { return true; } static function managed_runtime_enabled() { return true; } static function configured() { return false; } }
class MAD4B_SCP_Adapter_Registry { static function instance() { throw new RuntimeException( 'Sibling catalog factory ran' ); } }
class MAD4B_SCP_Developer_Runtime { static function tool_names( $breakglass ) { return array( $breakglass ? 'fixture/breakglass' : 'fixture/developer' ); } }
class MAD4B_SCP_Provider_Contracts { static function runtime_status( ...$args ) { throw new RuntimeException( 'Full provider certification ran' ); } }
class MAD4B_SCP_MCP_Peer_Governance { static function status() { throw new RuntimeException( 'Foreign inventory ran' ); } }
class MAD4B_SCP_External_Handshake_Evidence { static function status() { throw new RuntimeException( 'Full external certification ran' ); } }
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
			'input_schema_sha256' => hash( 'sha256', 'endpoint-schema:' . $name ),
			'classification_sha256' => hash( 'sha256', 'endpoint-classification:' . $name ),
			'execution_lane' => 'read',
			'descriptor_sha256' => hash( 'sha256', 'endpoint-descriptor:' . $name ),
			'generation_roots' => array( 'contract_root' => str_repeat( 'a', 64 ), 'site_root' => str_repeat( 'b', 64 ) ),
			'authorizing' => false,
		);
	}
}
class JobAdapter { public $tools = array(); function create_server( ...$args ) { $this->tools[$args[0]] = $args[9]; return true; } }
class JobRest { function get_routes() { return array( '/mcp/mad4b-developer-breakglass' => array() ); } }
function rest_get_server() { $GLOBALS['rest_calls']++; $GLOBALS['wp_rest_server'] = new JobRest(); try { do_action( 'rest_api_init' ); } catch ( Throwable $error ) { fwrite( STDERR, (string) $error . "\n" ); throw $error; } return $GLOBALS['wp_rest_server']; }
function check( $ok, $message ) { if ( ! $ok ) throw new RuntimeException( $message ); }
$GLOBALS['case'] = $case;
$GLOBALS['rest_calls'] = 0;
$GLOBALS['preserved'] = 0;
if ( 'foreign_adapter' === $case ) { class ForeignAdapter { static function instance() { throw new RuntimeException( 'Foreign runtime was armed' ); } } class_alias( 'ForeignAdapter', 'WP\\MCP\\Core\\McpAdapter' ); }
else { require $adapter_dir . '/vendor/autoload.php'; \WP\MCP\Core\McpAdapter::instance(); }
require MAD4B_SCP_DIR . 'includes/class-mad4b-scp-mcp-request-scope.php';
require MAD4B_SCP_DIR . 'includes/class-mad4b-scp-provider-diagnostic-policy.php';
require MAD4B_SCP_DIR . 'includes/class-mad4b-scp-servers.php';
require MAD4B_SCP_DIR . 'includes/class-mad4b-scp-connection-status.php';
require MAD4B_SCP_DIR . 'includes/class-mad4b-scp-endpoint-diagnostic.php';
$_GET = array();
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = array( 'action' => MAD4B_SCP_Endpoint_Diagnostic::ACTION, 'nonce' => 'valid', 'server_id' => 'mad4b-developer-breakglass', 'build' => MAD4B_SCP_Endpoint_Diagnostic::build_fingerprint() );
MAD4B_SCP_MCP_Request_Scope::bootstrap();
check( MAD4B_SCP_MCP_Request_Scope::current_request_is_passive_admin_hotpath() && ! MAD4B_SCP_MCP_Request_Scope::current_request_requires_mcp_runtime(), 'Unverified action armed runtime or entered lifecycle boot' );
check( ! MAD4B_SCP_Provider_Diagnostic_Policy::explicit_rest_materialization_allowed(), 'Raw request grants REST permission' );
add_action( 'rest_api_init', 'register_initial_settings', 10 ); add_action( 'rest_api_init', 'create_initial_rest_routes', 99 );
add_action( 'rest_api_init', 'endpoint_foreign_registrar', 10 ); add_action( 'rest_api_init', 'endpoint_preserved_registrar', 10 );
$job_adapter = new JobAdapter();
add_action( 'mcp_adapter_init', static function() use ( $job_adapter ) { ( new MAD4B_SCP_Servers() )->register_servers( $job_adapter ); }, 10 );
$expect = array( 'capability' => 'mad4b_endpoint_diagnostic_forbidden', 'nonce' => 'mad4b_endpoint_diagnostic_nonce_invalid', 'array_input' => 'mad4b_endpoint_diagnostic_input_invalid', 'server' => 'mad4b_endpoint_diagnostic_server_invalid', 'build' => 'mad4b_endpoint_diagnostic_build_changed', 'method' => 'mad4b_endpoint_diagnostic_post_required', 'foreign_adapter' => 'mad4b_endpoint_diagnostic_noncanonical_adapter', 'early_rest' => 'mad4b_endpoint_diagnostic_runtime_already_materialized' );
if ( 'nonce' === $case ) $_POST['nonce'] = 'invalid';
if ( 'array_input' === $case ) $_POST['server_id'] = array( 'mad4b-chatgpt' );
if ( 'server' === $case ) $_POST['server_id'] = 'other-server';
if ( 'build' === $case ) $_POST['build'] = str_repeat( '0', 64 );
if ( 'method' === $case ) $_SERVER['REQUEST_METHOD'] = 'GET';
if ( 'early_rest' === $case ) $GLOBALS['did']['rest_api_init'] = 1;
$result = MAD4B_SCP_Endpoint_Diagnostic::run();
if ( isset( $expect[$case] ) ) {
	check( is_wp_error( $result ) && $expect[$case] === $result->get_error_code(), 'Wrong rejection for ' . $case );
	check( 0 === $GLOBALS['rest_calls'] && '' === MAD4B_SCP_MCP_Request_Scope::endpoint_diagnostic_server_id(), 'Rejected request performed lifecycle work' );
} else {
	check( ! is_wp_error( $result ) && 1 === $GLOBALS['rest_calls'], 'Authorized job did not complete' );
	check( 9 === count( $job_adapter->tools ) && array( 'mad4b-developer-breakglass' ) === array_keys( array_filter( $job_adapter->tools ) ), 'Catalog selection: ' . json_encode( $job_adapter->tools ) );
	check( 1 === $GLOBALS['preserved'] && 1 === did_action( 'mcp_adapter_init' ), 'Canonical or preserved callback lost' );
	check( ! $result['connection_certified'] && ! $result['certification_performed'] && $result['foreign_transport_inventory_deferred'], 'Scoped job fabricated certification' );
	check( ! $result['server']['local_endpoint_ready'], 'Missing actual Adapter server admitted' );
	if ( 'evidence' === $case ) {
		$failure = array( 'failing_ability' => 'mad4b/example', 'stage' => 'official_schema_validation', 'error_code' => 'mcp_tool_validation_failed', 'error_class' => 'WP_Error', 'validator_reason' => 'input_schema_properties_not_object', 'tool_bytes' => 120, 'raw_payload' => 'PRIVATE_PAYLOAD' );
		$class_failure = array( 'alias' => 'tool_validator', 'class' => 'WP\\MCP\\Domain\\Tools\\McpToolValidator', 'expected_source' => 'mcp-adapter/includes/Domain/Tools/McpToolValidator.php', 'observed_source' => 'foreign-plugin/vendor/McpToolValidator.php', 'expected_sha256' => str_repeat( 'c', 64 ), 'actual_sha256' => str_repeat( 'd', 64 ), 'reason' => 'runtime_class_source_mismatch', 'absolute_path' => '/PRIVATE/ABSOLUTE/PATH' );
		$provenance = array( 'enforced' => true, 'ready' => false, 'state' => 'mixed_runtime', 'blocker' => 'mcp_adapter_class_provenance_mismatch', 'failure_count' => 1, 'failures' => array( $class_failure ) );
		$reflection = new ReflectionProperty( MAD4B_SCP_Servers::class, 'registrations' ); $reflection->setAccessible( true );
		$reflection->setValue( null, array( 'mad4b-chatgpt' => array( 'registered' => true, 'error' => 'mcp_adapter_class_provenance_mismatch', 'preflight' => array( 'ready' => false, 'failures' => array_fill( 0, 15, $failure ), 'runtime_class_provenance' => $provenance ) ) ) );
		$evidence = MAD4B_SCP_Connection_Status::endpoint_diagnostic( 'mad4b-chatgpt' );
		check( 12 === count( $evidence['preflight_failures'] ) && 15 === $evidence['preflight_failure_count'] && false === strpos( json_encode( $evidence ), 'PRIVATE_PAYLOAD' ), 'Failure evidence unbounded or private' );
		check( ! $evidence['local_endpoint_ready'] && 'official_schema_validation' === $evidence['preflight_failures'][0]['stage'] && 'input_schema_properties_not_object' === $evidence['preflight_failures'][0]['validator_reason'], 'Required validator evidence lost' );
		check( false === $evidence['runtime_class_provenance_ready'] && 'mixed_runtime' === $evidence['runtime_class_provenance_state'] && 1 === $evidence['runtime_class_failure_count'], 'Runtime class provenance summary lost' );
		check( 'tool_validator' === $evidence['runtime_class_failures'][0]['alias'] && 'foreign-plugin/vendor/McpToolValidator.php' === $evidence['runtime_class_failures'][0]['observed_source'], 'Class provenance evidence lost' );
		check( false === strpos( json_encode( $evidence ), '/PRIVATE/ABSOLUTE/PATH' ), 'Absolute class path leaked from endpoint diagnostics' );
	}
}
check( ! MAD4B_SCP_Provider_Diagnostic_Policy::explicit_rest_materialization_allowed(), 'Job authorization leaked' );
echo 'PASS endpoint diagnostic: ' . $case . "\n";
